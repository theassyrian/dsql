<?php

namespace atk4\dsql\tests;

use atk4\dsql\Connection;
use atk4\dsql\Expression;
use atk4\dsql\Query;

class SelectTest extends \PHPUnit_Extensions_Database_TestCase
{
    protected $pdo;

    /**
     * The connection returned from Connection::connect using a DSN
     * @var Connection|\PDO
     */
    protected $c;

    /**
     * An instance of \PDO, created with same credentials as self::$c
     * @var \PDO
     */
    protected $cpdo;

    public function __construct()
    {
        $this->c    = Connection::connect($GLOBALS['DB_DSN'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASSWD']);
        $this->cpdo = Connection::connect(new \PDO($GLOBALS['DB_DSN'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASSWD']));

        $this->pdo = $this->c->connection();

        $this->pdo->query('CREATE TABLE employee (id int not null, name text, surname text, retired bool, PRIMARY KEY (id))');
    }

    /**
     * @return PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    protected function getConnection()
    {
        return $this->createDefaultDBConnection($this->pdo, $GLOBALS['DB_DBNAME']);
    }

    /**
     * @return PHPUnit_Extensions_Database_DataSet_IDataSet
     */
    protected function getDataSet()
    {
        return $this->createFlatXMLDataSet(dirname(__FILE__).'/SelectTest.xml');
    }

    private function q($connection, $table = null, $alias = null)
    {
        $q = $connection->dsql();

        // add table to query if specified
        if ($table !== null) {
            $q->table($table, $alias);
        }

        return $q;
    }

    private function e($connection, $template = null, $args = null)
    {
        return $connection->expr($template, $args);
    }

    public function connProvider()
    {
        return [
            [$this->c],
            [$this->cpdo],
        ];
    }

    /**
     * @dataProvider rovider connProvider
     */
    public function testBasicQueries()
    {
        foreach ([$this->c, $this->cpdo] as $conn) {
            $this->assertEquals(4, $this->getConnection()->getRowCount('employee'));

            $this->assertEquals(
                ['name' => 'Oliver', 'surname' => 'Smith'],
                $this->q($conn, 'employee')->field('name,surname')->getRow()
            );

            $this->assertEquals(
                ['surname' => 'Taylor'],
                $this->q($conn, 'employee')->field('surname')->where('retired', '1')->getRow()
            );

            $this->assertEquals(
                4,
                $this->q($conn)->field(new Expression('2+2'))->getOne()
            );

            $this->assertEquals(
                4,
                $this->q($conn, 'employee')->field(new Expression('count(*)'))->getOne()
            );

            $names = [];
            foreach ($this->q($conn, 'employee')->where('retired', false) as $row) {
                $names[] = $row['name'];
            }
            $this->assertEquals(
                ['Oliver', 'Jack', 'Charlie'],
                $names
            );

            $this->assertEquals(
                [['now' => 4]],
                $this->q($conn)->field(new Expression('2+2'), 'now')->get()
            );

            /*
             * Postgresql needs to have values cast, to make the query work.
             * But CAST(.. AS int) does not work in mysql. So we use two different tests..
             * (CAST(.. AS int) will work on mariaDB, whereas mysql needs it to be CAST(.. AS signed))
             */
            if ('pgsql' === $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                $this->assertEquals(
                    [['now' => 6]],
                    $this->q($conn)->field(new Expression('CAST([] AS int)+CAST([] AS int)', [3, 3]), 'now')->get()
                );
            } else {
                $this->assertEquals(
                    [['now' => 6]],
                    $this->q($conn)->field(new Expression('[]+[]', [3, 3]), 'now')->get()
                );
            }

            $this->assertEquals(
                5,
                $this->q($conn)->field(new Expression('COALESCE([],5)', [null]), 'null_test')->getOne()
            );
        }
    }

    public function testExpression()
    {
        /*
         * Postgresql, at least versions before 10, needs to have the string cast to the
         * correct datatype.
         * But using CAST(.. AS CHAR) will return one single character on postgresql, but the
         * entire string on mysql.
         */
        if ('pgsql' === $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            $this->assertEquals(
                'foo',
                $this->e($this->c, 'select CAST([] AS TEXT)', ['foo'])->getOne()
            );
        } else {
            $this->assertEquals(
                'foo',
                $this->e($this->c, 'select CAST([] AS CHAR)', ['foo'])->getOne()
            );
        }
    }

    /**
     * covers atk4\dsql\Expression::__toString, but on PHP 5.5 this hint doesn't work.
     */
    public function testCastingToString()
    {
        // simple value
        $this->assertEquals(
            'Williams',
            (string) $this->q($this->c, 'employee')->field('surname')->where('name', 'Jack')
        );
        // table as sub-query
        $this->assertEquals(
            'Williams',
            (string) $this->q($this->c, $this->q($this->c, 'employee'), 'e2')->field('surname')->where('name', 'Jack')
        );
        // field as expression
        $this->assertEquals(
            'Williams',
            (string) $this->q($this->c, 'employee')->field($this->e($this->c, 'surname'))->where('name', 'Jack')
        );
        // cast to string multiple times
        $q = $this->q($this->c, 'employee')->field('surname')->where('name', 'Jack');
        $this->assertEquals(
            ['Williams', 'Williams'],
            [(string) $q, (string) $q]
        );
        // cast custom Expression to string
        $this->assertEquals(
            '7',
            (string) $this->e($this->c, 'select 3+4')
        );
    }

    public function testOtherQueries()
    {
        // truncate table
        $this->q($this->c, 'employee')->truncate();
        $this->assertEquals(
            0,
            $this->q($this->c, 'employee')->field(new Expression('count(*)'))->getOne()
        );

        // insert
        $this->q($this->c, 'employee')
            ->set(['id' => 1, 'name' => 'John', 'surname' => 'Doe', 'retired' => 1])
            ->insert();
        $this->q($this->c, 'employee')
            ->set(['id' => 2, 'name' => 'Jane', 'surname' => 'Doe', 'retired' => 0])
            ->insert();
        $this->assertEquals(
            [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']],
            $this->q($this->c, 'employee')->field('id,name')->order('id')->get()
        );
        $this->assertEquals(
            [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']],
            $this->q($this->c, 'employee')->field('id,name')->order('id')->select()->fetchAll()
        );

        // update
        $this->q($this->c, 'employee')
            ->where('name', 'John')
            ->set('name', 'Johnny')
            ->update();
        $this->assertEquals(
            [['id' => 1, 'name' => 'Johnny'], ['id' => 2, 'name' => 'Jane']],
            $this->q($this->c, 'employee')->field('id,name')->order('id')->get()
        );

        // replace
        if ('pgsql' !== $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            $this->q($this->c, 'employee')
                ->set(['id' => 1, 'name' => 'Peter', 'surname' => 'Doe', 'retired' => 1])
                ->replace();
        } else {
            $this->q($this->c, 'employee')
                ->set(['name' => 'Peter', 'surname' => 'Doe', 'retired' => 1])
                ->where('id', 1)
                ->update();
        }

        // In SQLite replace is just like insert, it just checks if there is
        // duplicate key and if it is it deletes the row, and inserts the new
        // one, otherwise it just inserts.
        // So order of records after REPLACE in SQLite will be [Jane, Peter]
        // not [Peter, Jane] as in MySQL, which in theory does the same thing,
        // but returns [Peter, Jane] - in original order.
        // That's why we add usort here.
        $data = $this->q($this->c, 'employee')->field('id,name')->get();
        usort($data, function ($a, $b) {
            return $a['id'] - $b['id'];
        });
        $this->assertEquals(
            [['id' => 1, 'name' => 'Peter'], ['id' => 2, 'name' => 'Jane']],
            $data
        );

        // delete
        $this->q($this->c, 'employee')
            ->where('retired', 1)
            ->delete();
        $this->assertEquals(
            [['id' => 2, 'name' => 'Jane']],
            $this->q($this->c, 'employee')->field('id,name')->get()
        );
    }

    /**
     * @expectedException Exception
     */
    public function testEmptyGetOne()
    {
        // truncate table
        $this->q($this->c, 'employee')->truncate();
        $this->q($this->c, 'employee')->field('name')->getOne();
    }

    public function testConnection()
    {

    }
}
