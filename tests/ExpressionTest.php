<?php

namespace atk4\dsql\tests;

use atk4\dsql\Expression;

/**
 * @coversDefaultClass \atk4\dsql\Expression
 */
class ExpressionTest extends \PHPUnit_Framework_TestCase
{

    public function e()
    {
        $args = func_get_args();
        switch (count($args)) {
            case 1:
                return new Expression($args[0]);
            case 2:
                return new Expression($args[0], $args[1]);
        }
        return new Expression();
    }



    /**
     * Test constructor exception - no parameters.
     *
     * @covers ::__construct
     */
    public function testConstructorException_1st_1()
    {
        $this->setExpectedException('atk4\dsql\Exception');
        $this->e();
    }

    /**
     * Test constructor exception - wrong 1st parameter.
     *
     * @covers ::__construct
     */
    public function testConstructorException_1st_2()
    {
        $this->setExpectedException('atk4\dsql\Exception');
        $this->e(false);
    }

    /**
     * Test constructor exception - wrong 2nd parameter.
     *
     * @covers ::__construct
     */
    public function testConstructorException_2nd_1()
    {
        $this->setExpectedException('atk4\dsql\Exception');
        $this->e("hello, []", false);
    }

    /**
     * Test constructor exception - wrong 2nd parameter.
     *
     * @covers ::__construct
     */
    public function testConstructorException_2nd_2()
    {
        $this->setExpectedException('atk4\dsql\Exception');
        $this->e("hello, []", "hello");
    }

    /**
     * Testing parameter edge cases - empty strings and arrays etc.
     *
     * @covers ::__construct
     */
    public function testConstructor_1()
    {
        $this->assertEquals(
            '',
            $this->e('')->render()
        );
        $this->assertEquals(
            '',
            $this->e([])->render()
        );
    }

    /**
     * Testing simple template patterns without arguments.
     *
     * @covers ::__construct
     */
    public function testConstructor_2()
    {
        $this->assertEquals(
            'now()',
            $this->e('now()')->render()
        );
    }

    /**
     * Testing template with simple arguments.
     *
     * @covers ::__construct
     */
    public function testConstructor_3()
    {
        $e = $this->e('hello, [who]', ['who' => 'world']);
        $this->assertEquals('hello, :a', $e->render());
        $this->assertEquals('world', $e->params[':a']);
    }

    /**
     * Testing template with complex arguments.
     *
     * @covers ::__construct
     */
    public function testConstructor_4()
    {
        // argument = Expression
        $this->assertEquals(
            'hello, world',
            $this->e('hello, [who]', ['who' => $this->e('world')])->render()
        );

        // multiple arguments = Expression
        $this->assertEquals(
            'hello, world',
            $this->e(
                '[what], [who]',
                [
                    'what' => $this->e('hello'),
                    'who'  => $this->e('world')
                ]
            )->render()
        );

        // numeric argument = Expression
        $this->assertEquals(
            'testing "hello, world"',
            $this->e(
                'testing "[]"',
                [
                    $this->e(
                        '[what], [who]',
                        [
                            'what' => $this->e('hello'),
                            'who'  => $this->e('world')
                        ]
                    )
                ]
            )->render()
        );

        // pass template as array
        $this->assertEquals(
            'hello, world',
            $this->e(
                ['template' => 'hello, [who]'],
                ['who' => $this->e('world')]
            )->render()
        );

    }

    /**
     * Test nested parameters
     *
     * @covers ::__construct
     * @covers ::_param
     * @covers ::getDebugQuery
     */
    public function testNestedParams()
    {
        // ++1 and --2
        $e1 = $this->e("[] and []", [
            $this->e('++[]', [1]),
            $this->e('--[]', [2]),
        ]);

        $this->assertEquals(
            '++1 and --2 [:b, :a]',
            strip_tags($e1->getDebugQuery())
        );

        $e2 = $this->e("=== [foo] ===", ['foo' => $e1]);

        $this->assertEquals(
            '=== ++1 and --2 === [:b, :a]',
            strip_tags($e2->getDebugQuery())
        );

        $this->assertEquals(
            '++1 and --2 [:b, :a]',
            strip_tags($e1->getDebugQuery())
        );
    }

    /**
     * Fully covers _escape method
     *
     * @covers ::_escape
     */
    public function testEscape()
    {
        // escaping expressions
        $this->assertEquals(
            '`first_name`',
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', ['first_name'])
        );
        $this->assertEquals(
            '*first_name*',
            PHPUnitUtil::callProtectedMethod($this->e(['escapeChar' => '*']), '_escape', ['first_name'])
        );

        // should not escape expressions
        $this->assertEquals(
            '*',
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', ['*'])
        );
        $this->assertEquals(
            '123',
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', [123])
        );
        $this->assertEquals(
            '(2+2) age',
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', ['(2+2) age'])
        );
        $this->assertEquals(
            'first_name.table',
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', ['first_name.table'])
        );
        $this->assertEquals(
            'first#name',
            PHPUnitUtil::callProtectedMethod($this->e(['escapeChar'=>'#']), '_escape', ['first#name'])
        );
        $this->assertEquals(
            true,
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', [new Date]) instanceof Date
        );

        // escaping array - escapes each of its elements
        $this->assertEquals(
            ['`first_name`', '*', '`last_name`'],
            PHPUnitUtil::callProtectedMethod($this->e(''), '_escape', [ ['first_name', '*', 'last_name'] ])
        );
    }

    /**
     * Test ArrayAccess implementation
     *
     * @covers ::offsetSet
     * @covers ::offsetExists
     * @covers ::offsetUnset
     * @covers ::offsetGet
     */
    public function testArrayAccess()
    {
        $e = $this->e('', ['parrot' => 'red', 'blue']);

        // offsetGet
        $this->assertEquals('red', $e['parrot']);
        $this->assertEquals('blue', $e[0]);

        // offsetSet
        $e['cat'] = 'black';
        $this->assertEquals('black', $e['cat']);
        $e['cat'] = 'white';
        $this->assertEquals('white', $e['cat']);
        
        // offsetExists, offsetUnset
        $this->assertEquals(true, isset($e['cat']));
        unset($e['cat']);
        $this->assertEquals(false, isset($e['cat']));

    }

    /**
     * Test IteratorAggregate implementation
     *
     * @covers ::getIterator
     */
    public function testIteratorAggregate()
    {
        // todo - can not test this without actual DB connection and executing expression
        null;
    }

    /**
     * Test for vendors that rely on JavaScript expressions, instead of parameters.
     *
     * @covers ::_param
     */
    public function testJsonExpression()
    {
        $e = new JsonExpression('hello, [who]', ['who' => 'world']);
        
        $this->assertEquals(
            'hello, "world"',
            $e->render()
        );
        $this->assertEquals(
            [],
            $e->params
        );
    }
}


// @codingStandardsIgnoreStart
class JsonExpression extends Expression
{
    public function _param($value)
    {
        return json_encode($value);
    }
}
// @codingStandardsIgnoreEnd
