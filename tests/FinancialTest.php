<?php
namespace Opine;

class FinancialTest extends \PHPUnit_Framework_TestCase {
    private $financial;
    private $db;

    public function setup () {
        date_default_timezone_set('UTC');
        $root = getcwd();
        $container = new Container($root, $root . '/container.yml');
        $this->financial = $container->financial;
        $this->db = $container->db;
    }

    public function testFinancial () {
        $this->assertTrue(true);
    }
}