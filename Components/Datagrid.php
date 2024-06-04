<?php
/**
 * @author Drajat Hasan
 * @email [drajathasan20@mail.com]
 * @create date 2024-05-26 10:27:23
 * @modify date 2024-05-26 10:27:23
 * @desc This is an Ui Component for SLiMS
 * with reusable method, PSR4 format etc.
 * Inspired from simbio datagrid created by Arie Nugraha.
 */

namespace SLiMS\Ui\Components;

use SLiMS\DB;
use Closure;

class Datagrid extends Table
{
    /**
     * Datagrid properties
     */
    protected array $properties = [
        // For debugging process
        'sql' => [
            'main' => '',
            'count' => '',
            'parameters' => []
        ],
        /* Basic properties for SQL process */
        'connection' => 'SLiMS',
        'driver' => 'mysql',
        'table' => '',
        'join' => [],
        'countable_column' => '*',
        'columns' => [],
        'criteria' => [],
        'group' => [],
        'sort' => [],
        'limit' => 30,
        'grammar' => [
            'mysql' => [
                'encapsulate_column' => '`',
                'encapsulete_value' => '\'',
                'pagination_pattern' => 'limit {limit} offset {offset}'
            ],
            'pgsql' => [
                'encapsulate_column' => '"',
                'encapsulete_value' => '\'',
                'pagination_pattern' => 'limit {limit} offset {offset}'
            ]
        ],
        /* End */
        /* Interface process */
        'editable' => true,
        'width_per_columns' => [],
        'unsortable_by_anchor' => [],
        'invisible_column' => [],
        'editable_form' => [
            'id' => '',
            'name' => '',
            'action' => '',
            'method' => 'POST',
            'target' => 'submitExec'
        ],
        'search_match' => [
            'keywords' => '',
            'query_time' => 0
        ],
        'bar' => [
            'question' => '',
            'class' => 's-btn btn btn-danger',
            'value' => '',
            'name' => 'delete'
        ],
        'cast' => [],
        /* end */
        /* Utility section for http event etc */
        'event' => [
            'on_search' => null,
            'on_fetch' => null,
            'on_cached' => null,
            'on_edit' => null,
            'on_delete' => null,
            'on_match' => null
        ],
        /* end */
        'custom_event_to_call' => []
    ];

    /**
     * Data properties
     */
    protected array $detail = [
        'record' => [],
        'total' => 0
    ];

    /**
     * Register some properties
     * based on user input
     *
     * @param string $name
     * @param string $action
     * @param string $method
     * @param string $target
     */
    public function __construct(string $name = 'datagrid', string $action = '', string $method = 'POST', string $target = 'submitExec',)
    {
        $this->properties['editable_form'] = [
            'id' => $this->cleanChar($name),
            'name' => $this->cleanChar($name),
            'action' => empty($action) ? $_SERVER['PHP_SELF'] : $action,
            'method' => $method,
            'target' => $target
        ];
    }

    /**
     * Cast and modify column content
     *
     * @param string $column
     * @param Closure $callback
     * @return string
     */
    public function cast(string $column, Closure $callback):string
    {
        $hasAlias = false;
        $columnAlias = [];
        $columnName = '';

        $this->aliasExtractor($column, $columnAlias, $hasAlias);
        $this->getOriginalColumnFromAlias($column, $columnName);

        $this->properties['cast'][($hasAlias ? $columnAlias[1] : $columnName)] = $callback;
        return $column;
    }

    /**
     * Set main table and join clause if needed
     *
     * @param string $table
     * @param array $joins
     * @return Datagrid
     */
    public function setTable(string $table, array $joins = []):Datagrid
    {
        $connectionProfile = config('database.nodes.' . $this->connection);

        if (isset($connectionProfile['driver'])) $this->driver = $connectionProfile['driver'];

        $this->table = $this->aliasExtractor($table);
        $joinType = ['join','inner join','right join','left join','outer join'];
        
        foreach ($joins as $join) {
            list($joinTable, $operands, $type) = $join;
            $joinTable = $this->aliasExtractor($joinTable);
            
            $chunkOperands = array_chunk($operands, 3);
            foreach ($chunkOperands as $key => $operand) {
                foreach ($operand as $k => $value) {
                    $operand[$k] = $this->aliasExtractor($value);
                }
                $chunkOperands[$key] = implode(' ', $operand);
            }

            if (!in_array(strtolower($type), $joinType)) continue;

            $this->table .= ' ' . strtolower($type) . ' ' . $joinTable . ' on ' . implode(' ', $chunkOperands);
        }

        return $this;
    }

    /**
     * Html width per column
     *
     * @param array $widthPerColumn
     * @return Datagrid
     */
    public function setColumnWidth(array $widthPerColumn):Datagrid
    {
        $this->properties['width_per_columns'] = $widthPerColumn;
        return $this;
    }

    /**
     * Add columns to datagrid
     *
     * @return Datagrid
     */
    public function setColumn():Datagrid
    {
        if (func_num_args() < 1) throw new Exception("Method addColumn need at least 1 argument!");

        $countableColumn = '';
        $countableColumn = $this->getOriginalColumnFromAlias(func_get_args()[0])[0];
        $this->countable_column = $this->cleanChar($countableColumn);
        
        $this->columns = array_map(function($col) {
            return $this->aliasExtractor($col);
        }, func_get_args());

        return $this;
    }

    /**
     * If it set to true datagrid will show up
     * editable attribute such as check all data, uncheck data
     * selected data etc.
     *
     * @param boolean $status
     * @return void
     */
    public function isEditable(bool $status):void
    {
        $this->editable = $status;
    }

    /**
     * Set sql criteria
     * 
     * @param array|string $column
     * @param mixed $value
     * @return Datagrid
     */
    public function setCriteria(array|string $column, $value = ''):Datagrid
    {
        if (empty($value)) {
            foreach ($column as $col) {
                $this->properties['criteria'][$col[0]] = $col[1];
            }

            return $this;
        }

        $this->properties['criteria'][$column] = $value;

        return $this;
    }

    /**
     * Set group by
     *
     * @return Datagrid
     */
    public function setGroup():Datagrid
    {
        $this->group = implode(',', array_map(fn($col) => $this->aliasExtractor($col), func_get_args()));
        return $this;
    }

    /**
     * Sorting data
     *
     * @param string|array $columnName
     * @param string $type
     * @return Datagrid
     */
    public function setSort(string|array $columnName, string $type = 'asc'):Datagrid
    {
        if (in_array(($type = strtolower($type)), ['asc','desc'])) {
            if (is_string($columnName)) $columnName = [$columnName];
            $this->sort = implode(',', array_map(fn($col) => $this->aliasExtractor($col), $columnName)) . ' ' . $type;
        }
        return $this;
    }

    /**
     * Register some column to unsortable
     * 
     * @param array|string $column
     * @return Datagrid
     */
    public function setUnsort(array|string $column):Datagrid
    {
        if (is_array($column)) {
            $column = array_merge($this->properties['unsortable_by_anchor'], $column);
            $this->properties['unsortable_by_anchor'] = $column;
        } else {
            $this->properties['unsortable_by_anchor'][] = $column;
        }

        return $this;
    }

    /**
     * Register some column to invisible on 
     * html rendering.
     *
     * @param array $column
     * @return Datagrid
     */
    public function setInvisibleColumn(array $column):Datagrid
    {
        $this->properties['invisible_column'] = $column;
        return $this;
    }

    /**
     * A method to handle serching data
     *
     * @param Closure $callback
     * @return Datagrid
     */
    public function onSearch(Closure $callback, string $searchQuery = 'keywords'):Datagrid
    {
        if (isset($_REQUEST[$searchQuery]) && !empty($_REQUEST[$searchQuery])) {
            $this->properties['event']['on_search'] = $callback;
            $this->properties['search_match']['keywords'] = htmlentities($_REQUEST[$searchQuery]);
        }

        return $this;
    }

    /**
     * Make pagination data
     *
     * @param integer $limit
     * @return Datagrid
     */
    public function setLimit(int $limit): Datagrid
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Compile criteria data
     *
     * @return array
     */
    protected function getWhere():array
    {
        $criteria = [];
        $parameters = [];
        foreach ($this->criteria as $column => $value) {
            if (is_callable($value)) {
                $customParams = [];
                $criteria[$column] = $this->aliasExtractor($column) . ' ' . $value($this, $customParams);
                $parameters = array_merge($parameters, $customParams);
                continue;
            }

            $criteria[$column] = $this->aliasExtractor($column) . ' = ?';
            $parameters[] = $value;
        }

        return [
            'criteria' => implode(' and ', $criteria),
            'parameters' => $parameters
        ];
    }

    /**
     * @param string $input
     * @return void
     */
    public function cleanChar(string $input):string
    {
        return str_replace(['\'','"','`','--'], '', $input);
    }

    /**
     * Encapsulate string between quote
     */
    public function encapsulate(string|array $input):string
    {
        // have alias?
        if (is_array($input)) {
            return implode('.', array_map(fn($char) => $this->openAndCloseWithGrammar($char), $input));
        }

        return $this->openAndCloseWithGrammar(trim($input));
    }

    /**
     * Retrieve original column without alias
     *
     * @param string $input
     * @param string $originalColumn
     * @return array
     */
    public function getOriginalColumnFromAlias(string $input, string &$originalColumn = ''):array
    {
        $extract = explode(' as ', str_replace(['AS','as','aS','As'], 'as', $input));

        $isDotExists = false;
        $dotAlias = $this->dotExtractor($input, $isDotExists);
        $originalColumn = $isDotExists ? $dotAlias[1] : $extract[0];

        return $extract;
    }

    /**
     * Extract some input based on dot char
     *
     * @param string $input
     * @param boolean $isAvailable
     * @return array|string
     */
    public function dotExtractor(string $input, bool &$isAvailable = false):array|string
    {
        $isAvailable = is_numeric(strpos($input, '.'));
        return $isAvailable ? explode('.', trim($input)) : $input;
    }
    
    /**
     * compile coloumn string into MySQL format
     *
     * @param string $column
     * @param array $extract
     * @param boolean $hasAlias
     * @return string
     */
    protected function aliasExtractor(string $column, array &$extract = [], bool &$hasAlias = false):string
    {
        $operator = [
            '+','-','*',
            '/','%','&',
            '|','^','=',
            '>','<','>=',
            '<=','<>','+=',
            '-=','*=', '/=',
            '%=','&=','^-=',
            '|*=','all','and',
            'any','between','exists',
            'in','like','not','or',
            'some'
        ];

        if (in_array(strtolower($column), $operator)) return $column;

        // bypass for raw query
        if (substr($column, 0,1) === '!') return trim($column,'!');

        $column = $this->cleanChar($column);
        $columnExtract = $this->getOriginalColumnFromAlias($column);
        if (($hasAlias = isset($columnExtract[1]))) {
            $extract = $columnExtract;
            return implode(' as ', array_map(function($col) {
                return $this->encapsulate($this->dotExtractor($col));
            }, $columnExtract));
        }

        return $this->encapsulate($this->dotExtractor($column));
    }

    /**
     * Generate url based on editable form action
     *
     * @param array $additionalUrl
     * @return string
     */
    protected function setUrl(array $additionalUrl = []):string
    {
        // seperate querystring and self
        $url = explode('?', $this->properties['editable_form']['action']);
        $overideArray = function($source, $new) {
            foreach ($new as $key => $value) {
                $source[$key] = $value;
            }

            return $source;
        };

        // had queries?
        if (isset($url[1])) {
            parse_str($url[1], $queries); // convert http query to array
            // merging and turn it back to http query format
            $url[1] = http_build_query($overideArray($queries, $additionalUrl));
        } else {
            // not http query? make it from additional url and $_GET
            $url[1] = http_build_query($overideArray($_GET, $additionalUrl));
        }

        // as string with queries
        return implode('?', $url);
    }

    /**
     * Retrieve data from database
     *
     * @return void
     */
    protected function getData(bool $withLimit = true)
    {
        $grammar = $this->properties['grammar'][$this->driver];

        // column processing
        $columns = implode(',', $this->columns);

        if (empty($this->table)) return;
        if (empty($columns)) $columns = '*';

        $sql = [];

        // Select statement
        $sql['select'] = 'select ' . $columns . ' from ' . $this->table;
        
        // Where clause
        $userSearch = $this->properties['event']['on_search'];

        if ($this->criteria || $userSearch) {
            $where = $this->getWhere();
            if (is_callable($userSearch)) {
                $parameters = [];
                $userSearch($this);
                $where = $this->getWhere();
                $where['parameters'] = array_merge($where['parameters'], $parameters);
            }

            $sql['criteria'] = 'where ' . $where['criteria'];
        }

        // Groupting data
        if ($this->group) {
            $sql['group'] = 'group by ' . $this->group;
        }

        // sorting data
        $direction = isset($_GET['dir']);

        if (isset($grammar['pagination_with_order']) && empty($this->sort) && !$direction) {
            $this->setSort($this->countable_column, 'asc');
        }

        if ($this->sort || $direction) {
            if ($direction) {
                $this->setSort($_GET['field'], $_GET['dir']);
            }
            $sql['order'] = 'order by ' . $this->sort;
        }

        // pagination
        if ($withLimit) {
            $offset = (((int)($_GET['page']??1) - 1) * $this->limit);
            $sql['limit'] = str_replace(
                ['{limit}','{offset}'], 
                [((int)$this->limit),$offset], 
                $grammar['pagination_pattern']
            );
        }

        // set main query
        $mainQuery = DB::query($rawMainQuery = implode(' ', $sql), $where['parameters']??[], $grammar['pdo_options']??[]);
        $mainQuery->setConnection($this->connection);

        if (!$withLimit) {
            return $mainQuery;
        }

        $startProcess = function_exists('microtime')?microtime(true):time();
        $this->detail['record'] = $mainQuery->toArray();
        $endProcess = function_exists('microtime')?microtime(true):time();

        $this->properties['search_match']['query_time'] = round($endProcess - $startProcess, 5);

        if (!empty($mainQueryError = $mainQuery->getError())) {
            throw new \Exception('Main query : ' . $mainQueryError . '. Raw Query [' . $this->connection . '] : ' . $rawMainQuery);
        }

        // set total query
        $totalSql = [];
        $distinct = isset($sql['group']) ? 'distinct ' : '';
        $totalSql['select'] = 'select count(' . trim($distinct . $this->countable_column) . ') as total from ' . $this->table;
        if (isset($sql['criteria'])) $totalSql['criteria'] = $sql['criteria'];
        
        $totalQuery = DB::query($rawTotalQuery = implode(' ', $totalSql), $where['parameters']??[]);
        $totalQuery->setConnection($this->connection);

        $result = $totalQuery->first();
        $this->detail['total'] = $result['total']??0;
        
        if (!empty($totalQueryError = $totalQuery->getError())) {
            throw new \Exception('Total query : ' . $totalQueryError . '. Raw Query [' . $this->connection . '] : ' . $rawTotalQuery);
        }

        $this->properties['sql'] = [
            'main' => $rawMainQuery,
            'count' => $rawTotalQuery,
            'parameters' => $where['parameters']??[]
        ];
    }

    /**
     * Preparing html table header
     *
     * @return void
     */
    protected function setHeader()
    {
        $header = [];

        if ($this->editable) {
            if (!isset($this->properties['queueable'])) {
                // deleted 
                $header[] = __('DELETE');
                // edita
                $header[] = __('EDIT');
            } else {
                $header[] = __('ADD');
            }
        }

        foreach (array_keys($this->detail['record'][0]??[]) as $key => $value) {
            // hidden some column
            if (in_array($value, $this->properties['invisible_column'])) continue;

            // set header as clear text if it available in unsortable list
            if ($this->editable === false || in_array($value, $this->properties['unsortable_by_anchor'])) {
                $header[] = $value;
                continue;
            }

            // editable? skip first column replaced by edit and delete
            if ($this->editable) {
                if ($key === 0) continue;
            }

            // Direction or sorting process
            $dir = 'DESC';
            if (isset($_GET['dir']) && isset($_GET['field']) && $_GET['field'] === $value) {
                $dir = $_GET['dir'] === 'ASC' ? 'DESC' : 'ASC';
            }

            // set http query
            $defaultParam = [
                'field' => $value,
                'dir' => $dir
            ];

            $header[] = (new Td)->setSlot((string)createComponent('a', [
                'href' => $this->setUrl($defaultParam)
            ])->setSlot($value));
        }

        // add header to datagrid
        $this->addHeader(...$header);

        // clear header variable
        unset($header);
    }

    /**
     * Preparing table body
     *
     * @return void
     */
    public function setBody()
    {
        $recordNum = 0;
        foreach ($this->detail['record'] as $columnName => $value) {
            $recordNum++;

            $originalValue = array_values($value);

            // default options for row attribute
            $options = [
                'class' => (($recordNum%2) === 0 ? 'alterCell2' : 'alterCell'),
                'style' => 'cursor: pointer',
                'row' => $recordNum
            ];

            // Value processing
            foreach ($value as $col => $val) {
                // hidden some column
                if (in_array($col, $this->properties['invisible_column'])) {
                    unset($value[$col]);
                    continue;
                }

                $td = new Td;

                // modify column content
                if (isset($this->properties['cast'][$col])) {
                    $value[$col] = call_user_func_array($this->properties['cast'][$col], [$this, $val, $value]);
                }

                // set default attribute
                $td->setAttribute('valign', 'top');

                // set column width
                if (isset($this->properties['width_per_columns'][$col])) {
                    $td->setAttribute('width', $this->properties['width_per_columns'][$col]);
                }

                // set content inner td
                $value[$col] = $td->setSlot($value[$col]??'');
                unset($td);
            }

            // Add row
            $columns = array_values($value);
            if ($this->editable) {
                $editableValue = [];

                // td options
                $tdOption = [
                    'align' => 'center',
                    'valign' => 'top',
                    'style' => 'width: 5%'
                ];

                // Checkbox
                $editableValue[] = createComponent('td', $tdOption)->setSlot(createComponent('input', [
                    'id' => 'cbRow' . $recordNum,
                    'class' => 'selected-row',
                    'type' => 'checkbox',
                    'name' => 'itemID[]',
                    'value' => $originalValue[0]
                ]));

                // edit button
                if (!isset($this->properties['queueable'])) {
                    $editableValue[] = createComponent('td', $tdOption)->setSlot(createComponent('a', [
                        'class' => 'editLink',
                        'href' => $this->setUrl($editableParam = ['itemID' => $originalValue[0], 'edit' => 'true']),
                        'postdata' => http_build_query($editableParam),
                        'title' => __('Edit')
                    ])->setSlot(''));
                }

                // remove first column
                unset($columns[0]);

                // new columns
                $columns = array_merge($editableValue, $columns);
            }

            // Add column to row
            $this->addRow($columns, $options);
            unset($column);
        }
    }

    public function setSearchInfo()
    {
        if (empty($this->search_match['keywords'])) return '';

        $message = str_replace('{result->num_rows}', $this->detail['total'], __('Found <strong>{result->num_rows}</strong> from your keywords'));
        return (string)createComponent('div', [
            'class' => 'infoBox'
        ])->setSlot(
            $message . ' : ' . 
            $this->search_match['keywords'] . ' ' . 
            __('Query took') . 
            ' <b>' . $this->search_match['query_time'] . '</b> ' . 
            __('second(s) to complete')
        );
    }

    /**
     * Set action bar such as
     * delete, checkall, uncheckall button
     * and pagination
     * to manage row on datagrid
     *
     * @return string
     */
    public function setActionBar():string
    {
        $question = $this->properties['bar']['question'];
        $buttonClass = $this->properties['bar']['class'];
        $buttonValue = $this->properties['bar']['value'];
        $buttonName = $this->properties['bar']['name'];

        if (empty($question)) {
            $question = __('Are You Sure Want to DELETE Selected Data?');
        }

        if (empty($buttonValue)) {
            $buttonValue = __('Delete Selected Data');
        }

        if (empty($buttonName)) {
            $buttonName = 'delete';
        }
        
        if ($this->editable) {
            $actionButton = (string)createComponent('td')->setSlot(
                (string)createComponent('input', [
                    'type' => 'hidden',
                    'name' => $buttonName,
                    'value' => 'yes'
                ]) .
                (string)createComponent('input', [
                    'class' => $buttonClass,
                    'type' => 'button',
                    'onclick' => '!chboxFormSubmit(\'' . $this->properties['editable_form']['name'] . '\', \'' . $question . '\', 1)',
                    'value' => $buttonValue
                ]) . 
                (string)createComponent('input', [
                    'class' => 'check-all button btn btn-default',
                    'type' => 'button',
                    'value' => __('Check All')
                ]) .
                (string)createComponent('input', [
                    'class' => 'uncheck-all button btn btn-default ml-1',
                    'type' => 'button',
                    'value' => __('Uncheck All')
                ])
            );
        } else {
            $actionButton = (string)createComponent('td')->setAttribute('width', '50%');
        }

        $pagiNation = '';
        if ($this->detail['total'] > $this->properties['limit']) {
            $pagiNation = (string)createComponent('td', ['class' => 'paging-area'])
                        ->setSlot((string)Pagination::create($this->setUrl(), $this->detail['total'], $this->properties['limit']));
        }


        return (string)createComponent('table', [
            'class' => 'datagrid-action-bar',
            'cellspacing' => 0,
            'cellpadding' => 5,
            'style' => 'width: 100%'
        ])->setSlot($actionButton . $pagiNation);
    }

    /**
     * Debug process
     *
     * @return void
     */
    public function debug(string $somethingToAdd = '')
    {
        // For development process
        ob_start();
        debugBox(function() use($somethingToAdd) {
            dump($this->properties['sql'], $this->detail);
            echo $somethingToAdd;
        });
        return ob_get_clean();
    }

    public function setProperty(string $propertyName, $value):self
    {
        if (isset($this->properties[$propertyName])) {

            $this->properties[$propertyName] = array_merge($this->properties[$propertyName], $value);
        }

        return $this;
    }

    /**
     * Some magic method
     *
     * @return boolean
     */
    public function __isset($key) {
        return isset($this->properties[$key]);
    }

    public function __set($key, $value) {
        if (isset($this->$key)) {
            $this->properties[$key] = $value;
        }
    }

    public function __get($key) {
        return $this->properties[$key]??null;
    }

    /**
     * Finally we need to print out
     * this object to string as html output
     *
     * @return string
     */
    public function __toString()
    {
        // Fetching data from database
        $this->getData();

        $this->callEvent(['delete','edit']);

        // set iframe to catch from result
        $submitExec = createComponent('iframe', [
            'id' => 'submitExec',
            'name' => 'submitExec',
            'class' => isDev() ? 'd-block' : 'd-none'
        ])->setSlot('');

        $debug = $this->debug($submitExec);

        if ($this->detail['total'] > 0) {
            // Add column header
            $this->setHeader();            

            // set column body
            $this->setBody();

            // rendering object to html
            $datagrid = parent::__toString();

            // searching info
            if ($this->properties['event']['on_match'] === null) {
                $searchInfo = $this->setSearchInfo();
            } else {
                $searchInfo = call_user_func_array($this->properties['event']['on_match'][0], [$this->detail, $this->search_match]);
            }

            // set form
            $actionBar = $this->setActionBar();
            if ($this->editable) {
                $this->properties['editable_form']['action'] = $this->setUrl();
                $datagrid = createComponent('form', $this->properties['editable_form'])
                                ->setSlot($actionBar . $datagrid . $actionBar);
            } else {
                $datagrid = $actionBar . $datagrid . $actionBar;
            }

            $output = (!isDev() ? $submitExec : $debug) . $searchInfo . ((string)$datagrid);
        } else {
            // No Data
            $this->setSlot(
                createComponent('tr', [
                    'row' => 0,
                    'style' => 'cursor: pointer;'
                ])->setSlot((string)(new Td)->setAttribute([
                    'class' => 's-table__no-data',
                    'align' => 'center'
                ])->setSlot(__('No Data')))
            );

            $output = $debug . parent::__toString();
        }

        return $output;
    }
}
