<?php
namespace Bolt\Storage\Field\Type;

use Bolt\Storage\EntityManager;
use Bolt\Storage\EntityProxy;
use Bolt\Storage\Mapping\ClassMetadata;
use Bolt\Storage\Query\QueryInterface;
use Bolt\Storage\QuerySet;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * This is one of a suite of basic Bolt field transformers that handles
 * the lifecycle of a field from pre-query to persist.
 *
 * @author Ross Riley <riley.ross@gmail.com>
 */
class RelationType extends FieldTypeBase
{
    /**
     * Relation fields can allow filters on the relations fetched. For now this is limited
     * to the id field because of the possible complexity of fetching and filtering
     * all the related data.
     *
     * For example the following queries:
     *     'pages', {'relationkey'=>'1'}
     *     'pages', {'relationkey'=>'1 || 2 || 3'}.
     *
     * Because the search is actually on the join table, we replace the
     * expression to filter the join side rather than on the main side.
     *
     * @param QueryInterface $query
     * @param ClassMetadata  $metadata
     */
    public function query(QueryInterface $query, ClassMetadata $metadata)
    {
        $field = $this->mapping['fieldname'];

        foreach ($query->getFilters() as $filter) {
            if ($filter->getKey() == $field) {

                // This gets the method name, one of andX() / orX() depending on type of expression
                $method = strtolower($filter->getExpressionObject()->getType()).'X';

                $newExpr = $query->getQueryBuilder()->expr()->$method();
                foreach ($filter->getParameters() as $k => $v) {
                    $newExpr->add("$field.to_id = :$k");
                }

                $filter->setExpression($newExpr);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function load(QueryBuilder $query, ClassMetadata $metadata)
    {
        $field = $this->mapping['fieldname'];
        $target = $this->mapping['target'];
        $boltname = $metadata->getBoltName();

        $from = $query->getQueryPart('from');

        if (isset($from[0]['alias'])) {
            $alias = $from[0]['alias'];
        } else {
            $alias = $from[0]['table'];
        }

        $query->addSelect($this->getPlatformGroupConcat("$field.to_id", $field, $query))
            ->leftJoin($alias, $target, $field, "$alias.id = $field.from_id AND $field.from_contenttype='$boltname' AND $field.to_contenttype='$field'")
            ->addGroupBy("$alias.id");
    }

    /**
     * @inheritdoc
     */
    public function hydrate($data, $entity, EntityManager $em = null)
    {
        $field = $this->mapping['fieldname'];
        $relations = array_filter(explode(',', $data[$field]));
        $values = [];
        foreach ($relations as $id) {
            $values[] = new EntityProxy($field, $id, $em);
        }
        $entity->$field = $values;
    }

    /**
     * @inheritdoc
     */
    public function persist(QuerySet $queries, $entity, EntityManager $em = null)
    {
        $field = $this->mapping['fieldname'];
        $target = $this->mapping['target'];
        $accessor = "get".$field;
        $relations = (array)$entity->$accessor();

        // Fetch existing relations

        $existingQuery = $em->createQueryBuilder()
                            ->select('*')
                            ->from($target)
                            ->where('from_id = ?')
                            ->andWhere('from_contenttype = ?')
                            ->andWhere('to_contenttype = ?')
                            ->setParameter(0, $entity->id)
                            ->setParameter(1, $entity->getContenttype())
                            ->setParameter(2, $field);
        $result = $existingQuery->execute()->fetchAll();
        $existing = array_map(
            function ($el) {
                return $el['to_id'];
            },
            $result
        );
        $proposed = array_map(
            function ($el) {
                return $el->reference;
            },
            $relations
        );

        $toInsert = array_diff($proposed, $existing);
        $toDelete = array_diff($existing, $proposed);

        foreach ($toInsert as $item) {
            $ins = $em->createQueryBuilder()->insert($target);
            $ins->values([
                'from_id'          => '?',
                'from_contenttype' => '?',
                'to_contenttype'   => '?',
                'to_id'            => '?'
            ])->setParameters([
                0 => $entity->id,
                1 => $entity->getContenttype(),
                2 => $field,
                3 => $item
            ]);

            $queries->append($ins);
        }

        foreach ($toDelete as $item) {
            $del = $em->createQueryBuilder()->delete($target);
            $del->where('from_id=?')
                ->andWhere('from_contenttype=?')
                ->andWhere('to_contenttype=?')
                ->andWhere('to_id=?')
                ->setParameters([
                0 => $entity->id,
                1 => $entity->getContenttype(),
                2 => $field,
                3 => $item
            ]);

            $queries->append($del);
        }
    }

    /**
     * @inheritdoc
     */
    public function getName()
    {
        return 'relation';
    }

    /**
     * Get platform specific group_concat token for provided column
     *
     * @param string       $column
     * @param string       $alias
     * @param QueryBuilder $query
     *
     * @return string
     */
    protected function getPlatformGroupConcat($column, $alias, QueryBuilder $query)
    {
        $platform = $query->getConnection()->getDatabasePlatform()->getName();

        switch ($platform) {
            case 'mysql':
                return "GROUP_CONCAT(DISTINCT $column) as $alias";
            case 'sqlite':
                return "GROUP_CONCAT(DISTINCT $column) as $alias";
            case 'postgresql':
                return "string_agg(distinct $column, ',') as $alias";
        }
    }
}
