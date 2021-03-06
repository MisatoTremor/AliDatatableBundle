<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DoctrineBuilder implements QueryInterface
{

    /** @var \Symfony\Component\DependencyInjection\ContainerInterface */
    protected $container;

    /** @var \Doctrine\ORM\EntityManager */
    protected $em;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $request;

    /** @var \Doctrine\ORM\QueryBuilder */
    protected $queryBuilder;

    /** @var string */
    protected $entity_name;

    /** @var string */
    protected $entity_alias;

    /** @var array */
    protected $fields;

    /** @var string */
    protected $order_field = NULL;

    /** @var string */
    protected $order_type = "asc";

    /** @var string */
    protected $where = NULL;

    /** @var array */
    protected $joins = array();

    /** @var boolean */
    protected $has_action = true;

    /** @var array */
    protected $fixed_data = NULL;

    /** @var closure */
    protected $renderer = NULL;

    /** @var boolean */
    protected $search_all = FALSE;

    /** @var array */
    protected $search_fields = array();

    /** @var array */
    protected $search_paths = array();

    /**
     * class constructor 
     * 
     * @param ContainerInterface $container 
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container    = $container;
        $this->em           = $this->container->get('doctrine.orm.entity_manager');
        $this->request      = $this->container->get('request');
        $this->queryBuilder = $this->em->createQueryBuilder();
    }

    /**
     * Returns the numeral index of $value in associative array $haystack.
     *
     * @param $needle
     * @param $haystack
     * @return bool|int
     */
    static function array_search_index($needle, $haystack)
    {
        $index = 0;
        foreach ($haystack as $key => $value) {
            if($value === $needle){
                return $index;
            }
            $index++;
        }
        return false;
    }

    /**
     * Returns a string of $length characters containing symbols from $charset.
     *
     * @param int $length
     * @param string $charset
     * @return string
     */
    static function randString($length, $charset='abcdefghijklmnopqrstuvwxyz')
    {
        $str = '';
        $count = strlen($charset) - 1;
        while ($length--) {
            $str .= $charset[mt_rand(0, $count)];
        }
        return $str;
    }

    /**
     * get the search dql
     * 
     * @return string
     */
    protected function _addSearch(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        if ($this->search == TRUE)
        {
            $request       = $this->request;
            $search_fields = array_values($this->fields);
            $expressions = array();
            foreach ($search_fields as $i => $search_field)
            {
                if(in_array($i, $this->search_fields)) {
                    if($this->search_all) {
                        $search_param = $request->get("sSearch");
                    } else {
                        $search_param = $request->get("sSearch_{$i}");
                    }
                    if ($search_param !== false && !empty($search_param))
                    {
                        if(isset($this->search_paths[$i])) {
                            $alias = self::randString(8);
                            $queryBuilder->leftJoin($search_field, $alias);
                            $expressions[] = $queryBuilder->expr()->like(
                                $alias . '.' . $this->search_paths[$i],
                                $queryBuilder->expr()->literal("%{$search_param}%")
                            );
                        } else {
                            $expressions[] = $queryBuilder->expr()->like(
                                $search_field,
                                $queryBuilder->expr()->literal("%{$search_param}%")
                            );
                        }
                    }
                }
            }
            if($this->search_all) {
                $expr = $queryBuilder->expr()->orX();
            } else {
                $expr = $queryBuilder->expr()->andX();
            }
            foreach ($expressions as $expression) {
                $expr->add($expression);
            }
            $queryBuilder->andWhere($expr);
        }
    }

    /**
     * convert object to array
     * @param object $object
     * @return array
     */
    protected function _toArray($object)
    {
        $reflectionClass = new \ReflectionClass(get_class($object));
        $array           = array();
        foreach ($reflectionClass->getProperties() as $property)
        {
            $property->setAccessible(true);
            $array[$property->getName()] = $property->getValue($object);
            $property->setAccessible(false);
        }
        return $array;
    }

    /**
     * add join
     * 
     * @example:
     *      ->setJoin( 
     *              'r.event', 
     *              'e', 
     *              \Doctrine\ORM\Query\Expr\Join::INNER_JOIN, 
     *              'e.name like %test%') 
     * 
     * @param string $join_field
     * @param string $alias
     * @param string $type
     * @param string $cond
     * 
     * @return Datatable 
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '')
    {
        if ($cond != '')
        {
            $cond = " with {$cond} ";
        }
        $join_method = $type == Join::INNER_JOIN ? "innerJoin" : "leftJoin";
        $this->queryBuilder->$join_method($join_field, $alias, null, $cond);
        return $this;
    }

    /**
     * get total records
     * 
     * @return integer 
     */
    public function getTotalRecords()
    {
        $qb = clone $this->queryBuilder;
        $this->_addSearch($qb);
        $qb->resetDQLPart('orderBy');

        $gb = $qb->getDQLPart('groupBy');
        if (empty($gb) || !in_array($this->fields['_identifier_'], $gb))
        {
            $qb->select(" count({$this->fields['_identifier_']}) ");
            return $qb->getQuery()->getSingleScalarResult();
        }
        else
        {
            $qb->resetDQLPart('groupBy');
            $qb->select(" count(distinct {$this->fields['_identifier_']}) ");
            return $qb->getQuery()->getSingleScalarResult();
        }
    }

    /**
     * get data
     * 
     * @param int $hydration_mode
     * @param bool $has_mutliple
     *
     * @return array 
     */
    public function getData($hydration_mode, $has_mutliple)
    {
        $request    = $this->request;
        $dql_fields = array_values($this->fields);
        if ($request->get('iSortCol_0') != null)
        {
            $order_field_index = max(0, $request->get('iSortCol_0') - ($has_mutliple ? 1 : 0));
            $order_field = current(explode(' as ', $dql_fields[$order_field_index]));
        }
        else
        {
            $order_field = null;
        }
        $qb = clone $this->queryBuilder;
        if (!is_null($order_field))
        {
            $qb->orderBy($order_field, $request->get('sSortDir_0', 'asc'));
        }
        else
        {
            $qb->resetDQLPart('orderBy');
        }
        $qb->select($this->entity_alias);
        $this->_addSearch($qb);
        $query          = $qb->getQuery();
        $iDisplayLength = (int) $request->get('iDisplayLength');
        if ($iDisplayLength > 0)
        {
            $query->setMaxResults($iDisplayLength)->setFirstResult($request->get('iDisplayStart'));
        }
        $objects      = $query->getResult(Query::HYDRATE_OBJECT);
        $selectFields = array();
        foreach ($this->fields as $label => $selector)
        {
            $has_alias      = preg_match_all('~([A-z]?\.[A-z]+)?\sas~', $selector, $matches);
            $_f             = ( $has_alias > 0 ) ? $matches[1][0] : $selector;
            $selectFields[] = substr($_f, strpos($_f, '.') + 1);
        }
        $data = array();
        foreach ($objects as $object)
        {
            $d   = array();
            $map = $this->_toArray($object);
            foreach ($selectFields as $key)
            {
                $d[] = $map[$key];
            }
            $data[] = $d;
        }
        return array($data, $objects);
    }

    /**
     * get entity name
     * 
     * @return string
     */
    public function getEntityName()
    {
        return $this->entity_name;
    }

    /**
     * get entity alias
     * 
     * @return string
     */
    public function getEntityAlias()
    {
        return $this->entity_alias;
    }

    /**
     * get fields
     * 
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * get order field
     *
     * @return string
     */
    public function getOrderField()
    {
        return $this->order_field;
    }

    /**
     * get order type
     * 
     * @return string
     */
    public function getOrderType()
    {
        return $this->order_type;
    }

    /**
     * get doctrine query builder
     * 
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getDoctrineQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * set entity
     * 
     * @param type $entity_name
     * @param type $entity_alias
     * 
     * @return Datatable 
     */
    public function setEntity($entity_name, $entity_alias)
    {
        $this->entity_name  = $entity_name;
        $this->entity_alias = $entity_alias;
        $this->queryBuilder->from($entity_name, $entity_alias);
        return $this;
    }

    /**
     * set fields
     * 
     * @param array $fields
     * 
     * @return Datatable 
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
        $this->queryBuilder->select(implode(', ', $fields));
        return $this;
    }

    /**
     * set order
     * 
     * @param type $order_field
     * @param type $order_type
     * 
     * @return Datatable 
     */
    public function setOrder($order_field, $order_type)
    {
        $this->order_field = $order_field;
        $this->order_type  = $order_type;
        $this->queryBuilder->orderBy($order_field, $order_type);
        return $this;
    }

    /**
     * set fixed data
     * 
     * @param type $data
     * 
     * @return Datatable 
     */
    public function setFixedData($data)
    {
        $this->fixed_data = $data;
        return $this;
    }

    /**
     * set query where
     * 
     * @param string $where
     * @param array  $params
     * 
     * @return Datatable 
     */
    public function setWhere($where, array $params = array())
    {
        $this->queryBuilder->where($where);
        $this->queryBuilder->setParameters($params);
        return $this;
    }

    /**
     * set query group
     * 
     * @param string $group
     * 
     * @return Datatable 
     */
    public function setGroupBy($group)
    {
        $this->queryBuilder->groupBy($group);
        return $this;
    }

    /**
     * set search
     * 
     * @param bool $search
     * 
     * @return Datatable
     */
    public function setSearch($search)
    {
        $this->search = $search;
        return $this;
    }

    /**
     * set search all
     *
     * @param bool $search_all
     *
     * @return Datatable
     */
    public function setSearchAll($search_all)
    {
        $this->search_all = $search_all;
        return $this;
    }

    /**
     * set search fields
     *
     * @param array $search_fields
     *
     * @return Datatable
     */
    function setSearchFields($search_fields)
    {
        $this->search_fields = $search_fields;
        return $this;
    }

    /**
     * set search fields
     *
     * @param array $search_paths
     *
     * @return Datatable
     */
    function setSearchPaths($search_paths)
    {
        $this->search_paths = $search_paths;
        return $this;
    }

    /**
     * set doctrine query builder
     * 
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * 
     * @return DoctrineBuilder 
     */
    public function setDoctrineQueryBuilder(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

}
