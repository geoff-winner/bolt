<?php



class Storage {
  
    var $db;
    var $config;
    var $prefix;
  
    function __construct(Silex\Application $app) {
    
        $this->config = $app['config'];
        $this->db = $app['db'];
        $this->monolog = $app['monolog'];
    
        $this->prefix = isset($this->config['general']['database']['prefix']) ? $this->config['general']['database']['prefix'] : "pilex_";
        
    }
  
    /** 
     * Check if just the users table is present.
     *
     * @return boolean
     */ 
    function checkUserTableIntegrity() {
        
        $sm = $this->db->getSchemaManager();

        $tables = $this->getTables();

        // Check the users table..
        if (!isset($tables[$this->prefix."users"])) {
            return false;            
        }
        
        return true;    
        
    }
  
  
  
    /**
     * Check if all required tables and columns are present in the DB
     *
     * @return boolean
     */
    function checkTablesIntegrity() {
        
        $sm = $this->db->getSchemaManager();

        $tables = $this->getTables();
        
        // Check the users table..
        if (!isset($tables[$this->prefix."users"])) {
            return false;            
        }
        
        
        
        // Check the taxonomy table..
        if (!isset($tables[$this->prefix."taxonomy"])) {
            return false;              
        }
        
        // Now, iterate over the contenttypes, and create the tables if they don't exist.
        foreach ($this->config['contenttypes'] as $key => $contenttype) {

            $tablename = $this->prefix . makeSlug($key);
            
            if (!isset($tables[$tablename])) {
                return false;  
            }
            
            // Check if all the fields are present in the DB..
            foreach($contenttype['fields'] as $field => $values) {

                // Skip over 'divider' fields.
                if ($values['type'] == "divider") {
                    continue;
                }
            
                if (!isset($tables[$tablename][$field])) {
                    return false;
                }
            }
            
        }

         
        return true;    
        
    }
  
  
    function repairTables() {

        $sm = $this->db->getSchemaManager();
      
        $output = array();

        $tables = $this->getTables();

        echo "<pre>\n" . util::var_dump($tables, true) . "</pre>\n";

        // Check the users table..
        if (!isset($tables[$this->prefix."users"])) {

            $schema = new \Doctrine\DBAL\Schema\Schema();
            $myTable = $schema->createTable($this->prefix."users"); 
            $myTable->addColumn("id", "integer", array("unsigned" => true, 'autoincrement' => true));
            $myTable->setPrimaryKey(array("id"));
            $myTable->addColumn("username", "string", array("length" => 32));
            $myTable->addColumn("password", "string", array("length" => 64));
            $myTable->addColumn("email", "string", array("length" => 64));
            $myTable->addColumn("lastseen", "datetime");                        
            $myTable->addColumn("lastip", "string", array("length" => 32));
            $myTable->addColumn("displayname", "string", array("length" => 32));
            $myTable->addColumn("userlevel", "string", array("length" => 32));
            $myTable->addColumn("enabled", "boolean");
            
            $queries = $schema->toSql($this->db->getDatabasePlatform());
            $queries = implode("; ", $queries);
            $this->db->query($queries);

            $output[] = "Created table <tt>" . $this->prefix."users" . "</tt>.";
            
        }
        
         
        // Check the taxonomy table..
        if (!isset($tables[$this->prefix."taxonomy"])) {

            $schema = new \Doctrine\DBAL\Schema\Schema();
            $myTable = $schema->createTable($this->prefix."taxonomy"); 
            $myTable->addColumn("id", "integer", array("unsigned" => true, 'autoincrement' => true));
            $myTable->setPrimaryKey(array("id"));
            $myTable->addColumn("content_id", "integer", array("unsigned" => true));
            $myTable->addColumn("contenttype", "string", array("length" => 32));
            $myTable->addColumn("taxonomytype", "string", array("length" => 32));
            $myTable->addColumn("slug", "string", array("length" => 64));   
            $myTable->addColumn("name", "string", array("length" => 64));
            
            $queries = $schema->toSql($this->db->getDatabasePlatform());
            $queries = implode("; ", $queries);
            $this->db->query($queries);
            
            $output[] = "Created table <tt>" . $this->prefix."taxonomy" . "</tt>.";
            
        }
        
        // Now, iterate over the contenttypes, and create the tables if they don't exist.
        foreach ($this->config['contenttypes'] as $key => $contenttype) {

            // create the table if necessary.. 
            $tablename = $this->prefix . makeSlug($key);
            
            if (!isset($tables[$tablename])) {
                
                $schema = new \Doctrine\DBAL\Schema\Schema();
                $myTable = $schema->createTable($tablename); 
                $myTable->addColumn("id", "integer", array("unsigned" => true, 'autoincrement' => true));
                $myTable->setPrimaryKey(array("id"));
                $myTable->addColumn("slug", "string", array("length" => 128));
                $myTable->addColumn("datecreated", "datetime");    
                $myTable->addColumn("datechanged", "datetime"); 
                $myTable->addColumn("username", "string", array("length" => 32));
                $myTable->addColumn("status", "string", array("length" => 32));


                $queries = $schema->toSql($this->db->getDatabasePlatform());
                $queries = implode("; ", $queries);
                $this->db->query($queries);
                
                $output[] = "Created table <tt>" . $tablename . "</tt>.";
                
            }
            
            // Check if all the fields are present in the DB..
            foreach($contenttype['fields'] as $field => $values) {
                
                if (!isset($tables[$tablename][$field])) { 
          
                    $myTable = $sm->listTableDetails($tablename);
            
                    switch($values['type']) {
                        
                        case 'text':
                        case 'templateselect':
                        case 'image':
                        case 'file':
                            $query = sprintf("ALTER TABLE `%s` ADD `%s` VARCHAR( 256 ) NOT NULL DEFAULT \"\";", $tablename, $field);
                            $this->db->query($query);
                            $output[] = "Added column <tt>" . $field . "</tt> to table <tt>" . $tablename . "</tt>.";
                            break;

                        case 'number':
                            $query = sprintf("ALTER TABLE `%s` ADD `%s` DECIMAL(18,9) NOT NULL DEFAULT 0;", $tablename, $field);
                            $this->db->query($query);
                            $output[] = "Added column <tt>" . $field . "</tt> to table <tt>" . $tablename . "</tt>.";
                            break;
                            
                        case 'html':
                        case 'textarea':
                            $query = sprintf("ALTER TABLE `%s` ADD `%s` TEXT NOT NULL DEFAULT \"\";", $tablename, $field);
                            $this->db->query($query);
                            $output[] = "Added column <tt>" . $field . "</tt> to table <tt>" . $tablename . "</tt>.";
                            break;
                            
                        case 'datetime':
                        case 'date':
                            $query = sprintf("ALTER TABLE `%s` ADD `%s` DATETIME", $tablename, $field);
                            $this->db->query($query);
                            $output[] = "Added column <tt>" . $field . "</tt> to table <tt>" . $tablename . "</tt>.";
                            break; 
                            
                        case 'slug':
                        case 'id':
                        case 'datecreated':
                        case 'datechanged':
                        case 'username':
                        case 'divider':
                            // These are the default columns. Don't try to add these. 
                            break;
                        
                        default: 
                            $output[] = "Type <tt>" .  $values['type'] . "</tt> is not a correct field type for field <tt>$field</tt> in table <tt>$tablename</tt>.";
                        
                    }
                
                
                }


            }
            
            
        }

        return $output;
      
    }
    
    public function preFill() {

        $this->guzzleclient = new Guzzle\Service\Client('http://loripsum.net/api/');

        $output = "";
        
        // get a list of images..
        $this->images = findFiles('', 'jpg,jpeg,png');
        
        foreach ($this->config['contenttypes'] as $key => $contenttype) {
            
            $amount = isset($contenttype['prefill']) ? $contenttype['prefill'] : 5;
        
            for($i=1; $i<= $amount; $i++) {
                $output .= $this->preFillSingle($key, $contenttype);
            }
            
            
        }
        
        
        $output .= "\n\nDone!";
        
        return $output;
        
    }
    
    private function preFillSingle($key, $contenttype) {
           
        $slug = makeSlug($key);
        $tablename = $this->prefix . $slug;

        
        $content = array();
        $title = "";
        
        $content['contenttype'] = $key;
        $content['datecreated'] = date('Y-m-d H:i:s', time() - rand(0, 365*24*60*60));
        

        //todo: fix this, use a random name.
        $content['username'] = "admin";

        switch(rand(1,12)) {
            case 1: 
                $content['status'] = "timed";
                break;
            case 2: 
                $content['status'] = "draft";
                break;
            case 3: 
                $content['status'] = "depublished";
                break;
            default:
                $content['status'] = "published";
                break;
        }

        foreach($contenttype['fields'] as $field => $values) {
            
            switch($values['type']) {
                    
                case 'text':
                    $content[$field] = trim(strip_tags($this->guzzleclient->get('1/veryshort')->send()->getBody(true)));
                    if (empty($title)) { $title = $content[$field]; }
                    break;
                    
                case 'image':
                    // Get a random image..
                    if (!empty($this->images)) {
                        $content[$field] = $this->images[array_rand($this->images)];
                    }
                    break;
                    
                case 'html':
                case 'textarea':
                    if (in_array($field, array('teaser', 'introduction', 'excerpt', 'intro'))) {
                        $params = 'medium/decorate/link/1';
                    } else {
                        $params = 'medium/decorate/link/ol/ul/3';
                        //$params = 'long/1';

                    }
                    $content[$field] = trim($this->guzzleclient->get($params)->send()->getBody(true));
                    break;
                    
                case 'datetime':
                case 'date':
                    $content[$field] = date('Y-m-d H:i:s', time() - rand(-365*24*60*60, 365*24*60*60));
                    break; 
                    
            }
                        
        }

        if (!empty($contenttype['taxonomy'])) {
            foreach($contenttype['taxonomy'] as $taxonomy) {
                if (isset($this->config['taxonomy'][$taxonomy]['options'])) {
                    $options = $this->config['taxonomy'][$taxonomy]['options'];
                    $content['taxonomy'][$taxonomy] = $options[array_rand($options)];
                }   
            }
        }

        $this->saveContent($content);        
        
        $output = "Added to <tt>$key</tt> '" .$content['title'] . "'<br>\n";
        
        return $output;
        
    }
    
    
    public function saveContent($content, $contenttype="") {
              
        if (empty($contenttype) && !empty($content['contenttype'])) {
            $contenttype = $content['contenttype'];
        }
       
        if (empty($contenttype)) {
            echo "Contenttype is required.";
            return false;
        }
                        
        // Make an array with the allowed columns. these are the columns that are always present.
        $allowedcolumns = array('id', 'slug', 'datecreated', 'datechanged', 'username', 'status', 'taxonomy');
        // add the fields for this contenttype, 
        foreach ($this->config['contenttypes'][$contenttype]['fields'] as $key => $values) {

            $allowedcolumns[] = $key;
            
            // Set the slug, while we're at it..
            if ($values['type'] == "slug" && !empty($values['uses']) && empty($content['slug'])) {              
                $content['slug'] = makeSlug($content[ $values['uses'] ]);
            } 
            
        }
   
        // Clean up fields, check unneeded columns.
        foreach($content as $key => $value) {
        
            // parse 'formatted dates'.. Wednesday, 15 August 2012 -> 2012-08-15
            if (strpos($key, "-dateformatted") !== false) {
                $newkey = str_replace("-dateformatted", "", $key);
                
                // See if we need to add the time..
                if (isset($content[$newkey.'-timeformatted']) && !empty($content[$newkey.'-timeformatted'])) {
                    $value .= " - " . $content[$newkey.'-timeformatted'];
                } else {
                    $value .= " - 00:00";
                }
                
                $timestamp = DateTime::createFromFormat("l, d F Y - H:i", $value);

                if ($timestamp instanceof DateTime) {
                    $content[$newkey] = $timestamp->format('Y-m-d H:i:00');
                } else {
                    $content[$newkey] = "";
                }

            }
        
            if (!in_array($key, $allowedcolumns)) {
                // unset columns we don't need to store..
                unset($content[$key]);
            } else {
                // Trim strings..
                if (is_string($content[$key])) {
                    $content[$key] = trim($content[$key]);
                }
            }
        }            
            
            
        // Decide whether to insert a new record, or update an existing one.
        if (empty($content['id'])) {
            return $this->insertContent($content, $contenttype, $allowedcolumns);
        } else {
            return $this->updateContent($content, $contenttype);
        }
        
    }
    
    
    public function changeContent($contenttype="", $id, $column, $value) {
    
        if (empty($contenttype)) {
            echo "Contenttype is required.";
            return false;
        }
        
        // Make an array with the allowed columns. these are the columns that are always present.
        $allowedcolumns = array('id', 'slug', 'datecreated', 'datechanged', 'username', 'status');
        // add the fields for this contenttype, 
        foreach ($this->config['contenttypes'][$contenttype]['fields'] as $key => $values) {
            $allowedcolumns[] = $key;
        }        
        
        $content = array('id' => $id, $column => $value); 
        
        return $this->updateContent($content, $contenttype);
        
    }
    
    
    
    public function deleteContent($contenttype="", $id) {
    
        if (empty($contenttype)) {
            echo "Contenttype is required.";
            return false;
        }
               
        $tablename = $this->prefix . $contenttype;
                
        return $this->db->delete($tablename, array('id' => $id));
        
    }
        
    
    protected function insertContent($content, $contenttype, $allowedcolumns) {
        
        $tablename = $this->prefix . $contenttype;
        
        $content['datecreated'] = date('Y-m-d H:i:s');
        $content['datechanged'] = date('Y-m-d H:i:s');
        
        // Keep taxonomy for later.
        if (isset($content['taxonomy'])) {
            $taxonomy = $content['taxonomy'];
            unset($content['taxonomy']);
        }

        $res = $this->db->insert($tablename, $content);
        
        $id = $this->db->lastInsertId();

        // Add the taxonomies, if present.
        if (isset($taxonomy)) {
            $this->updateTaxonomy($contenttype, $id, $taxonomy);
        }
        
        return $res;
        
    }
    
    
    private function updateContent($content, $contenttype) {

        $tablename = $this->prefix . $contenttype;
        
        // Update the taxonomies, if present.
        if (isset($content['taxonomy'])) {
            $this->updateTaxonomy($contenttype, $content['id'], $content['taxonomy']);
            unset($content['taxonomy']);
        }
        
        unset($content['datecreated']);
        $content['datechanged'] = date('Y-m-d H:i:s');

        return $this->db->update($tablename, $content, array('id' => $content['id']));
        
    }
        
        
    public function updateSingleValue($id, $contenttype, $field, $value) {

        $tablename = $this->prefix . $contenttype;
        
        // Update the taxonomies, if present.
        if (isset($content['taxonomy'])) {
            $this->updateTaxonomy($contenttype, $content->id, $content['taxonomy']);
            unset($content['taxonomy']);
        }
        
        $id = intval($id);
        
        // TODO: make sure datechanged is updated
        unset($content['datecreated']);
        //$content['datechanged'] = date('Y-m-d H:i:s');

        //echo "<pre>\n" . util::var_dump($content, true) . "</pre>\n";

        //echo "table: $tablename \n\n";
        //echo "id: " . $id . " \n\n";

        $query = "UPDATE $tablename SET $field = ? WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(1, $value);
        $stmt->bindValue(2, $id);
        $res = $stmt->execute();

        return $res;
        
    }        
        
    public function getEmptyContent($contenttype) {
        
        $contenttype = $this->getContentType($contenttype);
        
        $content = array(
            'id' => '',
            'slug' => '',
            'datecreated' => '',
            'datechanged' => '',
            'username' => '',
            'status' => ''
        );
        
        
        foreach ($contenttype['fields'] as $key => $field) {
            $content[$key] = '';
            
            // Set the default values. 
            if (isset($field['default'])) {
                $content[$key] = $field['default'];
            } else {
                $content[$key] = '';
            }
            
        }
        
        // echo "<pre>\n" . util::var_dump($content, true) . "</pre>\n";
                
        return $content;
        

    }
    
    public function getContent($contenttypeslug, $parameters="", &$pager = array()) {

        // Some special cases, like 'entry/1' or 'page/about' need to be caught before further processing.
        if (preg_match_all('#^([a-z0-9_-]+)/([0-9]+)$#i', $contenttypeslug, $match)) {
            // like 'entry/12'
            $contenttypeslug = $match[1][0];
            $parameters['id'] = $match[2][0];
            $returnsingle = true;
        } else if (preg_match_all('#^([a-z0-9_-]+)/([a-z0-9_-]+)$#i', $contenttypeslug, $match)) {
            // like 'page/lorem-ipsum-dolor'
            $contenttypeslug = $match[1][0];
            $parameters['slug'] = $match[2][0];
            $returnsingle = true;
        } else if (preg_match_all('#^([a-z0-9_-]+)/(latest|first)/([0-9]+)$#i', $contenttypeslug, $match)) {
            // like 'page/lorem-ipsum-dolor'
            $contenttypeslug = $match[1][0];
            $parameters['order'] = 'datecreated ' . ($match[2][0]=="latest" ? "DESC" : "ASC");
            $parameters['limit'] = $match[3][0];
        }
        
        
        $limit = !empty($parameters['limit']) ? $parameters['limit'] : 100;
        $page = !empty($parameters['page']) ? $parameters['page'] : 1;

        // If we're allowed to use pagination, use the 'page' parameter.
        if (!empty($parameters['paging'])) {
            $page = !empty($_REQUEST['page']) ? $_REQUEST['page'] : $page;
        }

        $contenttype = $this->getContentType($contenttypeslug);
        
        // If requesting something with a content-type slug in singular, return only the first item.
        if ( ($contenttype['singular_slug'] == $contenttypeslug) || $parameters['returnsingle'] ) {
            $returnsingle = true;
        }
        
        $tablename = $this->prefix . $contenttype['slug'];

        // for all the non-reserved parameters that are fields, we assume people want to do a 'where'
        foreach($parameters as $key => $value) {
            if (in_array($key, array('order', 'where', 'limit', 'offset'))) {
                continue; // Skip this one..
            }
            if (!in_array($key, $this->getContentTypeFields($contenttype['slug'])) && 
                !in_array($key, array("id", "slug", "datecreated", "datechanged", "username", "status")) ) {
                continue; // Also skip if 'key' isn't a field in the contenttype.
            }

            $where[] = $this->parseWhereParameter($key, $value);

        }
        
        // If we need to filter, add the WHERE for that.
        // InnoDB doesn't support full text search. WTF is up with that shit? 
        if (!empty($parameters['filter'])){
            
            $filter = safeString($parameters['filter']);
        
            $filter_where = array();
            
            foreach($contenttype['fields'] as $key => $value) {
                if (in_array($value['type'], array('text', 'textarea', 'html'))) {
                    $filter_where[] = sprintf("`%s` LIKE '%%%s%%'", $key, $filter);
                }
            }
            
            if (!empty($filter_where)) {
                $where[] = "(" . implode(" OR ", $filter_where) . ")";
            }
            
        }

        $queryparams = "";

        // implode 'where'
        if (!empty($where)) {
            $queryparams .= " WHERE (" . implode(" AND ", $where) . ")";
        }        
        
        // Order 
        if (!empty($parameters['order'])) {
            $order = safeString($parameters['order']);
            if ($order[0] == "-") {
                $order = substr($order,1) . " DESC";
            }
            $queryparams .= " ORDER BY " . $order;
        }
        
        // Make the query for the pager..
        $pagerquery = "SELECT COUNT(*) AS count FROM $tablename" . $queryparams;        
        
        // Add the limit
        $queryparams .= sprintf(" LIMIT %s, %s;", ($page-1)*$limit, $limit);

        // Make the query to get the results..
        $query = "SELECT * FROM $tablename" . $queryparams;

        if (!$returnsingle) {
        //     echo "<pre>" . util::var_dump($query, true) . "</pre>";
        }

        $rows = $this->db->fetchAll($query);
        
        // Make sure content is set, and all content has information about its contenttype
        $content = array();
        foreach($rows as $key => $value) {
            $content[ $value['id'] ] = new Content($value, $contenttype); 
        }
        
        // Make sure all content has their taxonomies
        $this->getTaxonomy($content);

        // Iterate over the contenttype's taxonomy, check if there's one we can use for grouping.
        // If so, iterate over the content, and set ['grouping'] for each unit of content.
        // But only if we're not sorting manually (i.e. have a ?order=.. parameter or $parameter['order'] )
        if (empty($_GET['order']) && empty($parameters['order'])) {
            $have_grouping = false;
            $taxonomy = $this->getContentTypeTaxonomy($contenttypeslug);
            foreach($taxonomy as $taxokey => $taxo) {
                if ($taxo['behaves_like']=="grouping") {
                    $have_grouping = true;
                    break;
                }
            }
    
            if ($have_grouping) {
                uasort($content, function($a, $b) { 
                    if ($a->group == $b->group) { return 0; }
                    return ($a->group < $b->group) ? -1 : 1;
                });
            }
        }
        
        if (!$returnsingle) {
            // Set up the $pager array with relevant values..
            $rowcount = $this->db->executeQuery($pagerquery)->fetch();
            $pager = array(
                'for' => $contenttypeslug,
                'count' => $rowcount['count'],
                'totalpages' => ceil($rowcount['count'] / $limit),
                'current' => $page,
                'showing_from' => ($page-1)*$limit + 1,
                'showing_to' => ($page-1)*$limit + count($content)
            );

            $GLOBALS['pager'][$contenttypeslug] = $pager;
        }

        // If we requested a singular item..
        if ($returnsingle) {
            if (util::array_first_key($content)) {            
                return util::array_first($content);
            } else {
                return false;
            }
        } else {
            return $content;            
        }
        
    }

    /**
     * Helper function to set the proper 'where' parameter,
     * when getting values like '<2012' or '!bob'
     */
    private function parseWhereParameter($key, $value) {

        // Set the correct operator for the where clause
        $operator = "=";

        if ($value[0] == "!") {
            $operator = "!=";
            $value = substr($value, 1);
        } else if (substr($value, 0, 2) == "<=") {
            $operator = "<=";
            $value = substr($value, 2);
        } else if (substr($value, 0, 2) == ">=") {
            $operator = ">=";
            $value = substr($value, 2);
        } else if ($value[0] == "<") {
            $operator = "<";
            $value = substr($value, 1);
        } else if ($value[0] == ">") {
            $operator = ">";
            $value = substr($value, 1);
        } else if ($value[0] == "%" || $value[strlen($value)-1] == "%" ) {
            $operator = "LIKE";
        }

        $parameter = sprintf("%s %s %s", $this->db->quoteIdentifier($key), $operator, $this->db->quote($value));


        return $parameter;

    }


    /**
     * Get a single unit of content:
     * 
     * examples: 
     * $content = $app['storage']->getSingleContent("page/1");
     * $content = $app['storage']->getSingleContent("entry", array('where' => "slug = 'lorem-ipsum'"));
     * $content = $app['storage']->getSingleContent($contenttype['slug'], array('where' => "id = '$slug'"));
     *
     */
    public function getSingleContent($contenttypeslug, $parameters=array()) {
        
        // Just to make sure we're getting a single item. 
        $parameters['returnsingle'] = true;
        
        return $this->getContent($contenttypeslug, $parameters);
        
    }
        
    
    
    public function getContentType($contenttypeslug) {
    
        $contenttypeslug = makeSlug($contenttypeslug);

        // Return false if empty, can't find it..
        if (empty($contenttypeslug)) {
            return false;
        }  
        
        // echo "<pre>\n" . util::var_dump($this->config['contenttypes'], true) . "</pre>\n";

        // See if we've either given the correct contenttype, or try to find it by name or singular_name.
        if (isset($this->config['contenttypes'][$contenttypeslug])) {
            $contenttype = $this->config['contenttypes'][$contenttypeslug];
        } else {
            foreach($this->config['contenttypes'] as $key => $ct) {
                if ($contenttypeslug == makeSlug($ct['singular_name']) || $contenttypeslug == makeSlug($ct['name'])) {
                    $contenttype = $this->config['contenttypes'][$key];
                }
            }
            
        }
    
        if (!empty($contenttype)) {
    
            $contenttype['slug'] = makeSlug($contenttype['name']);
            $contenttype['singular_slug'] = makeSlug($contenttype['singular_name']);
    
            return $contenttype;
        
        } else {
            return false;
        }
    
    
    }
    
    
    /** 
     * Get an array of the available fields for a given contenttype
     *
     * @param string $contenttypeslug
     * @return array $fields
     */   
    public function getContentTypeFields($contenttypeslug) {
        
        $contenttype = $this->getContentType($contenttypeslug);
        
        if (empty($contenttype['fields'])) {
            return array();
        } else {
           return array_keys($contenttype['fields']);        
        }
    
    }
    
    /** 
     * Get an array of the available taxonomytypes for a given contenttype
     *
     * @param string $contenttypeslug
     * @return array $taxonomy
     */
    public function getContentTypeTaxonomy($contenttypeslug) {
        
        $contenttype = $this->getContentType($contenttypeslug);
        
        if (empty($contenttype['taxonomy'])) {
            return array();
        } else {
            $taxokeys = $contenttype['taxonomy'];
            
            $taxonomy = array();
            
            foreach ($taxokeys as $key) {
                $taxonomy[$key] = $this->config['taxonomy'][$key];
            }
            
            return $taxonomy;        
        }
    
    }    
    

    
    /**
     * Get the taxonomy for one or more units of content, return the array with the taxonomy attached.
     *
     * @param array $content
     * 
     * @return array $content
     */
    protected function getTaxonomy($content) {
        
        $tablename = $this->prefix . "taxonomy";
        
        $ids = util::array_pluck($content, 'id');
        
        if (empty($ids)) {
            return $content;
        }
              
        // Get the contenttype from first $content
        $contenttype = $content[ util::array_first_key($content) ]->contenttype['slug'];
       
       
        $taxonomytypes = array_keys($this->config['taxonomy']);

        $query = sprintf("SELECT * FROM $tablename WHERE content_id IN (%s) AND contenttype=%s AND taxonomytype IN ('%s')", 
                implode(", ", $ids), 
                $this->db->quote($contenttype),
                implode("', '", $taxonomytypes)
            );
        $rows = $this->db->fetchAll($query);

        foreach($rows as $key => $row) {
            $content[ $row['content_id'] ]->setTaxonomy($row['taxonomytype'], $row['slug']);
        }
        

        
    }


    /**
     * Update / insert taxonomy for a given content-unit.
     * 
     * @param string $contenttype
     * @param integer $content_id
     * @param array $taxonomy
     */
    protected function updateTaxonomy($contenttype, $content_id, $taxonomy) {
    
        $tablename = $this->prefix . "taxonomy";

        foreach($taxonomy as $taxonomytype => $newvalues) {
            if (!is_array($newvalues)) {
                $newvalues = explode(",", $newvalues);
            }
            
            // Get the current values from the DB..
            $query = "SELECT id, slug FROM $tablename WHERE content_id=? AND contenttype=? AND taxonomytype=?";
            $currentvalues = $this->db->fetchAll($query, array($content_id, $contenttype, $taxonomytype));
            $currentvalues = makeValuePairs($currentvalues, 'id', 'slug');
     
            // Add the ones not yet present.. 
            foreach($newvalues as $value) {
            
                if (!in_array($value, $currentvalues) && (!empty($value))) {
                    // Insert it! 
                    $row = array(
                            'content_id' => $content_id, 
                            'contenttype' => $contenttype, 
                            'taxonomytype' => $taxonomytype, 
                            'slug' => $value
                        );
                    $this->db->insert($tablename, $row);
                    // echo "insert: $content_id, $value<br />";
                }
                
            }
            
            // Delete the ones that have been removed. 
            // Add the ones not yet present.. 
            foreach($currentvalues as $id => $value) {
            
                if (!in_array($value, $newvalues)) {
                    // Delete it! 
                    $row = array(
                            'content_id' => $content_id, 
                            'contenttype' => $contenttype, 
                            'taxonomytype' => $taxonomytype, 
                            'slug' => $value
                        );
                    $this->db->delete($tablename, array('id' => $id));
                    // echo "delete: $id, $value<br />";
                }
            }            
            
        }
    
    }


    public function getUri($title, $id=0, $contenttypeslug, $fulluri=true) {
        
        
        $id = intval($id);     
        $fulluri = util::str_to_bool($fulluri);
            
        $slug = makeSlug($title);
        
        //echo "<pre>\n" . util::var_dump($contenttypeslug, true) . "</pre>\n";
        
        $contenttype = $this->getContentType($contenttypeslug);
        $tablename = $this->prefix . $contenttype['slug'];
        
        // Only add 'entry/' if $full is requested.
        if ($fulluri) {
            $prefix = "/" . $contenttype['singular_slug'] . "/";
        }
        
        $query = "SELECT id from $tablename WHERE slug='$slug' and id!='$id';";
        $res = $this->db->query($query)->fetch();
        
        if (!$res) {
            $uri = $prefix . $slug;
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $newslug = $slug.'-'.$i;
                $query = "SELECT id from $tablename WHERE slug='$newslug' and id!='$id';";
                $res = $this->db->query($query)->fetch();
                if (!$res) {
                    $uri = $prefix . $newslug;
                    break;
                }
            }
            
            // otherwise, just get a random slug.
            if (empty($uri)) {
                $slug = trimText($slug, 32, false, false) . "-" . makeKey(6);
                $uri = $prefix . $slug;
            }
        }
                
        return $uri;
        
    }
    
    /**
     * Get an associative array with the pilex_tables tables and columns in the DB.
     *
     * @return array
     */
    protected function getTables() {
        
        $sm = $this->db->getSchemaManager();

        $tables = array();        
        
        foreach ($sm->listTables() as $table) {
            if ( strpos($table->getName(), $this->prefix) == 0 ) {
                foreach ($table->getColumns() as $column) {
                    $tables[ $table->getName() ][ $column->getName() ] = $column->getType(); 
                }
                // $output[] = "Found table <tt>" . $table->getName() . "</tt>.";
            }
        }
        
        return $tables;
        
    }
  
    
  
}