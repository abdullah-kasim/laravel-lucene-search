<?php namespace Nqxcode\LaravelSearch;

use Illuminate\Database\Eloquent\Model;
use ZendSearch\Lucene\Search\Query\AbstractQuery;
use ZendSearch\Lucene\Search\Query\Boolean as QueryBoolean;
use ZendSearch\Lucene\Search\QueryHit;

use App;
use Input;
use ZendSearch\Lucene\Search\QueryParser;

class QueryRunner
{
    /**
     * @var Search
     */
    private $search;

    /**
     * @var string|AbstractQuery
     */
    private $query;


    /**
     * The maximum number of records to return.
     *
     * @var int
     */
    protected $limit;

    /**
     * The number of records to skip.
     *
     * @var int
     */
    protected $offset;

    /**
     * Callback functions to help manipulate the raw query instance.
     *
     * @var callable[]
     */
    protected $queryFilters = [];

    /**
     * List of cached query totals.
     *
     * @var array
     */
    private $cachedQueryTotals;

    /**
     * Last executed query.
     *
     * @var
     */
    private static $lastQuery;

    /**
     * Get last executed query.
     *
     * @return mixed
     */
    public static function getLastQuery()
    {
        return self::$lastQuery;
    }

    /**
     * Is the raw query build?
     *
     * @var boolean
     */
    private $isRawQueryBuilt = false;

    /**
     * Is query callback filters already executed?
     *
     * @var bool
     */
    private $isQueryFiltersExecuted = false;

    /**
     * @param Search $search
     * @param QueryBoolean $query
     */
    public function __construct(Search $search, QueryBoolean $query)
    {
        $this->search = $search;
        $this->query = $query;
    }

    /**
     * Build raw query.
     *
     * @param $query
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function rawQuery($query)
    {
        if ($query instanceof AbstractQuery) {
            $this->query = $query;
        } elseif (is_callable($query)) {
            $this->query = $query();
        } elseif (is_string($query)) {
            $this->query = $query;
        } else {
            throw new \InvalidArgumentException(
                "Argument 'query' must be a string or ZendSearch\\Lucene\\Search\\Query\\AbstractQuery instance or " .
                "callable returning a string or ZendSearch\\Lucene\\Search\\Query\\AbstractQuery instance."
            );
        }

        $this->isRawQueryBuilt = true;
        return $this;
    }

    /**
     * Use filter for query customization.
     *
     * @param callable $callable
     *
     * @return $this
     */
    public function addFilter(callable $callable)
    {
        $this->queryFilters[] = $callable;

        return $this;
    }

    /**
     * Add where clause to the query for phrase search.
     *
     * @param string $field
     * @param mixed $value
     * @param array $options - field      : field name
     *                       - value      : value to match
     *                       - required   : should match (boolean)
     *                       - prohibited : should not match (boolean)
     *                       - phrase     : phrase match (boolean)
     *                       - proximity  : value of distance between words (unsigned integer)
     **                      - fuzzy      : value of fuzzy(float, 0 ... 1)
     * @return $this
     */
    public function where($field, $value, array $options = [])
    {
        $this->query = $this->addSubquery($this->query, [
            'field' => $field,
            'value' => $value,
            'required' => array_get($options, 'required', true),
            'prohibited' => array_get($options, 'prohibited', false),
            'phrase' => array_get($options, 'phrase', true),
            'fuzzy' => array_get($options, 'fuzzy', null),
            'proximity' => array_get($options, 'proximity', null),
        ]);

        return $this;
    }

    /**
     * Add a basic search clause to the query.
     *
     * @param $value
     * @param $field
     * @param array $options - required   : should match (boolean)
     *                       - prohibited : should not match (boolean)
     *                       - phrase     : phrase match (boolean)
     *                       - proximity  : value of distance between words (unsigned integer)
     **                      - fuzzy      : value of fuzzy(float, 0 ... 1)
     * @return $this
     */
    public function find($value, $field = '*', array $options = [])
    {
        $this->query = $this->addSubquery($this->query, [
            'field' => $field,
            'value' => $value,
            'required' => array_get($options, 'required', true),
            'prohibited' => array_get($options, 'prohibited', false),
            'phrase' => array_get($options, 'phrase', false),
            'fuzzy' => array_get($options, 'fuzzy', null),
            'proximity' => array_get($options, 'proximity', null),
        ]);

        return $this;
    }

    /**
     * Set the "limit" and "offset" value of the query.
     *
     * @param int $limit
     * @param int $offset
     *
     * @return $this
     */
    public function limit($limit, $offset = 0)
    {
        $this->limit = $limit;
        $this->offset = $offset;

        return $this;
    }

    /**
     * Execute the current query and delete all found models from the search index.
     *
     * @return void
     */
    public function delete()
    {
        $models = $this->get();

        foreach ($models as $model) {
            $this->search->delete($model);
        }
    }

    /**
     * Execute the current query and return a paginator for the results.
     *
     * @param int $perPage
     *
     * @return \Illuminate\Pagination\Paginator
     */
    public function paginate($perPage = 25)
    {
        $page = intval(Input::get('page', 1));
        $this->limit($perPage, ($page - 1) * $perPage);
        return App::make('paginator')->make($this->get(), $this->count(), $perPage);
    }

    /**
     * Execute the current query and return the total number of results.
     *
     * @return int
     */
    public function count()
    {
        $this->executeQueryFilters();

        if (isset($this->cachedQueryTotals[md5(serialize($this->query))])) {
            return $this->cachedQueryTotals[md5(serialize($this->query))];
        }

        return count($this->executeQuery($this->query));
    }

    /**
     * Execute current query and return list of models.
     *
     * @return Model[]
     */
    public function get()
    {
        $options = [];

        if ($this->limit) {
            $options['limit'] = $this->limit;
            $options['offset'] = $this->offset;
        }

        // Modify query if filters were added.
        $this->executeQueryFilters();

        // Get all query hits.
        $hits = $this->executeQuery($this->query, $options);

        // Convert all hits to models.
        return $this->search->config()->models($hits);
    }

    /**
     * Add subquery to boolean query.
     *
     * @param QueryBoolean $query
     * @param array $options
     * @return QueryBoolean
     * @throws \RuntimeException
     */
    protected function addSubquery($query, array $options)
    {
        if (!$this->isRawQueryBuilt && $query instanceof QueryBoolean) {
            list($value, $sign) = $this->buildRawLuceneQuery($options);
            $query->addSubquery(QueryParser::parse($value), $sign);
            return $query;
        } else {
            throw new \RuntimeException("Can't use chain methods on the raw query.");
        }
    }

    /**
     * Build raw Lucene query by given options.
     *
     * @param array $options - field      : field name
     *                       - value      : value to match
     *                       - phrase     : phrase match (boolean)
     *                       - required   : should match (boolean)
     *                       - prohibited : should not match (boolean)
     *                       - proximity  : value of distance between words (unsigned integer)
     **                      - fuzzy      : value of fuzzy(float, 0 ... 1)
     * @return array contains string query and sign
     */
    protected function buildRawLuceneQuery($options)
    {
        $field = array_get($options, 'field');

        $value = trim($this->escapeSpecialChars(array_get($options, 'value')));

        if (empty($field) || '*' === $field) {
            $field = null;
        }

        if (isset($options['fuzzy']) && false !== $options['fuzzy']) {
            $fuzzy = '';
            if (is_numeric($options['fuzzy']) && $options['fuzzy'] >= 0 && $options['fuzzy'] <= 1) {
                $fuzzy = $options['fuzzy'];
            }

            $words = array();
            foreach (explode(' ', $value) as $word) {
                $words[] = $word . '~' . $fuzzy;
            }
            $value = implode(' ', $words);
        }

        if (array_get($options, 'phrase') || array_get($options, 'proximity')) {
            $value = '"' . $value . '"';
        } else {
            $value = $this->escapeSpecialOperators($value);
        }

        if (isset($options['proximity']) && false !== $options['proximity']) {
            if (is_integer($options['proximity']) && $options['proximity'] > 0) {
                $proximity = $options['proximity'];
                $value = $value . '~' . $proximity;
            }
        }

        if (is_array($field)) {
            $values = array();
            foreach ($field as $f) {
                $values[] = trim($f) . ':(' . $value . ')';
            }
            $value = implode(' OR ', $values);
        } elseif ($field) {
            $value = trim($field) . ':(' . $value . ')';
        }

        $sign = null;
        if (!empty($options['required'])) {
            $sign = true;
        }
        if (!empty($options['prohibited'])) {
            $sign = false;
        }

        return [$value, $sign];
    }

    /**
     * Escape special characters for Lucene query.
     *
     * @param string $str
     *
     * @return string
     */
    protected function escapeSpecialChars($str)
    {
        // List of all special chars.
        $special_chars = ['\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':'];


        // Escape all special characters.
        foreach ($special_chars as $ch) {
            $str = str_replace($ch, "\\{$ch}", $str);
        }

        return $str;
    }

    /**
     * Escape special operators for Lucene query.
     *
     * @param $str
     * @return mixed
     */
    protected function escapeSpecialOperators($str)
    {
        // List of query operators.
        $query_operators = ['to', 'or', 'and', 'not'];

        // Add spaces to operators.
        $query_operators = array_map(function ($operator) {
            return " {$operator} ";
        }, $query_operators);

        // Remove other operators.
        $str = str_ireplace($query_operators, ' ', $str);

        return $str;
    }

    /**
     * Execute added callback functions.
     *
     * @return void
     */
    protected function executeQueryFilters()
    {
        // Prevent multiple executions.
        if ($this->isQueryFiltersExecuted) {
            return;
        }

        foreach ($this->queryFilters as $callback) {
            if ($query = $callback($this->query)) {
                $this->query = $query;
            }
        }

        $this->isQueryFiltersExecuted = true;
    }

    /**
     * Execute the given query and return the query hits.
     *
     * @param string|AbstractQuery $query
     * @param array $options - limit  : max number of records to return
     *                       - offset : number of records to skip
     * @return array|QueryHit
     */
    protected function executeQuery($query, array $options = [])
    {
        $hits = $this->search->index()->find($query);

        // Remember total number of results.
        $this->cachedQueryTotals[md5(serialize($query))] = count($hits);

        // Remember running query.
        self::$lastQuery = $query;

        // Limit results.
        if (isset($options['limit']) && isset($options['offset'])) {
            $hits = array_slice($hits, $options['offset'], $options['limit']);
        }

        return $hits;
    }
}
