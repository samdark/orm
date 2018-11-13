<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM\Loader;

use Spiral\Database\Query\SelectQuery;
use Spiral\ORM\Exception\LoaderException;
use Spiral\ORM\Loader\Traits\ColumnsTrait;
use Spiral\ORM\LoaderInterface;
use Spiral\ORM\Node\AbstractNode;
use Spiral\ORM\ORMInterface;
use Spiral\ORM\Schema;

/**
 * Provides ability to load relation data in a form of JOIN or external query.
 */
abstract class RelationLoader extends AbstractLoader
{
    use ColumnsTrait;

    // require to properly calculate unique column aliases while minified
    private static $countLevels = 0;

    /**
     * Default set of relation options. Child implementation might defined their of default options.
     *
     * @var array
     */
    protected $options = [
        'method' => null, // load method, see AbstractLoader constants
        'minify' => true, // when true all loader columns will be minified (only for loading)
        'alias'  => null, // table alias
        'using'  => null, // alias used by another relation
        'where'  => null, // where conditions (if any)
    ];

    /** @var string */
    protected $relation;

    /** @var array */
    protected $schema;

    /**
     * @param ORMInterface $orm
     * @param string       $class
     * @param string       $relation
     * @param array        $schema
     */
    public function __construct(ORMInterface $orm, string $class, string $relation, array $schema)
    {
        parent::__construct($orm, $class);
        $this->relation = $relation;
        $this->schema = $schema;
    }

    /**
     * {@inheritdoc}
     */
    public function withContext(LoaderInterface $parent, array $options = []): LoaderInterface
    {
        /**
         * @var AbstractLoader $parent
         * @var self           $loader
         */
        $loader = parent::withContext($parent, $options);

        if ($loader->getDatabase() !== $parent->getDatabase()) {
            if ($loader->isJoined()) {
                throw new LoaderException(
                    'Unable to join tables located in different databases'
                );
            }

            // loader is not joined, let's make sure that POSTLOAD is used
            if ($this->isLoaded()) {
                $loader->options['method'] = self::POSTLOAD;
            }
        }

        //Calculate table alias
        $loader->options['alias'] = $loader->calculateAlias($parent);

        return $loader;
    }

    /**
     * {@inheritdoc}
     */
    public function loadData(AbstractNode $node)
    {
        if ($this->isJoined() || !$this->isLoaded()) {
            // load data for all nested relations
            parent::loadData($node);

            return;
        }

        $references = $node->getReferences();
        if (empty($references)) {
            // nothing found at parent level, unable to create sub query
            return;
        }

        //Ensure all nested relations
        $statement = $this->configureQuery($this->initQuery(), $references)->run();
        $statement->setFetchMode(\PDO::FETCH_NUM);

        foreach ($statement as $row) {
            $node->parseRow(0, $row);
        }

        $statement->close();

        // load data for all nested relations
        parent::loadData($node);
    }

    /**
     * Indicated that loaded must generate JOIN statement.
     *
     * @return bool
     */
    public function isJoined(): bool
    {
        if (!empty($this->options['using'])) {
            return true;
        }

        return in_array($this->getMethod(), [self::INLOAD, self::JOIN, self::LEFT_JOIN]);
    }

    /**
     * Indication that loader want to load data.
     *
     * @return bool
     */
    public function isLoaded(): bool
    {
        return $this->getMethod() !== self::JOIN && $this->getMethod() !== self::LEFT_JOIN;
    }

    /**
     * Get load method.
     *
     * @return int
     */
    protected function getMethod(): int
    {
        return $this->options['method'];
    }

    /**
     * Configure query with conditions, joins and columns.
     *
     * @param SelectQuery $query
     * @param array       $outerKeys Set of OUTER_KEY values collected by parent loader.
     * @return SelectQuery
     */
    protected function configureQuery(SelectQuery $query, array $outerKeys = []): SelectQuery
    {
        if ($this->isJoined()) {
            // mounting the columns to parent query
            $this->mountColumns($query, $this->options['minify']);
        } else {
            // this is initial set of columns (remove all existed)
            $this->mountColumns($query, $this->options['minify'], '', true);
        }

        return parent::configureQuery($query);
    }

    /**
     * Create relation specific select query.
     *
     * @return SelectQuery
     */
    protected function initQuery(): SelectQuery
    {
        return $this->getDatabase()->select()->from($this->getJoinedTable());
    }

    /**
     * Calculate table alias.
     *
     * @param AbstractLoader $parent
     * @return string
     */
    protected function calculateAlias(AbstractLoader $parent): string
    {
        if (!empty($this->options['alias'])) {
            return $this->options['alias'];
        }

        if ($this->isLoaded() && $this->isJoined()) {
            // unique alias, we are able to do that for relations just loaded
            return 'd' . decoct(++self::$countLevels);
        }

        // chained alias
        return $parent->getAlias() . '_' . $this->relation;
    }

    /**
     * Generate sql identifier using loader alias and value from relation definition. Key name to be
     * fetched from schema.
     *
     * Example:
     * $this->getKey(Relation::OUTER_KEY);
     *
     * @param mixed $key
     * @return string|null
     */
    protected function localKey($key): ?string
    {
        if (empty($this->schema[$key])) {
            return null;
        }

        return $this->getAlias() . '.' . $this->schema[$key];
    }

    /**
     * Get parent identifier based on relation configuration key.
     *
     * @param mixed $key
     * @return string
     */
    protected function parentKey($key): string
    {
        return $this->parent->getAlias() . '.' . $this->schema[$key];
    }

    /**
     * Joined table name and alias.
     *
     * @return string
     */
    protected function getJoinedTable(): string
    {
        return "{$this->define(Schema::TABLE)} AS {$this->getAlias()}";
    }

    /**
     * Relation table alias.
     *
     * @return string
     */
    protected function getAlias(): string
    {
        if (!empty($this->options['using'])) {
            //We are using another relation (presumably defined by with() to load data).
            return $this->options['using'];
        }

        if (!empty($this->options['alias'])) {
            return $this->options['alias'];
        }

        throw new LoaderException("Unable to resolve loader alias");
    }

    /**
     * Relation columns.
     *
     * @return array
     */
    protected function getColumns(): array
    {
        return $this->define(Schema::COLUMNS);
    }
}