<?php

include_once(dirname(__FILE__).'/../Test_mysqld.php');

class Test_mysqldTest extends PHPUnit_Framework_TestCase
{
    public function testRaii()
    {
        $my_cnf = array('skip-networking' => '');
        $opts   = array();
        if (getenv('TEST_MYSQLD_DIR')) {
            $opts['base_dir'] = getenv('TEST_MYSQLD_DIR');
        }

        $path = system('which mysql_install_db');
        rtrim($path, "\n");
        if ($path) {
            $path = preg_replace('/\/[^\/]+\/mysql_install_db/', '', $path);
            if (!in_array($path, Test_mysqld::$SEARCH_PATHS)) {
                Test_mysqld::$SEARCH_PATHS[] = $path;
            }
        }
        $mysqld = new Test_mysqld($my_cnf, $opts);

        $base_dir = $mysqld->getBaseDir();
        $dsn = $mysqld->dsn();

        $ref_dsn = "mysql:dbname=test;unix_socket={$base_dir}/tmp/mysql.sock";

        $this->assertEquals($ref_dsn, $dsn, "取得したDSNが異なっています");

        $db = new PDO($ref_dsn);
        $err = $db->errorInfo();
        $this->assertNull($err[1], "接続に失敗しています");

        $mysqld->stop();
        sleep(1);

        $this->assertFalse(file_exists("{$base_dir}/tmp/mysql.sock"), 'mysqlがダウンしてないです');
    }

    public function testMulti()
    {
        $my_cnf = array('skip-networking' => '');
        $opts   = array();
        if (getenv('TEST_MYSQLD_DIR')) {
            $opts['base_dir'] = getenv('TEST_MYSQLD_DIR');
        }

        $path = system('which mysql_install_db');
        rtrim($path, "\n");
        if ($path) {
            $path = preg_replace('/\/[^\/]+\/mysql_install_db/', '', $path);
            if (!in_array($path, Test_mysqld::$SEARCH_PATHS)) {
                Test_mysqld::$SEARCH_PATHS[] = $path;
            }
        }

        $instances = array();
        foreach(range(1, 2) as $i) {
            $mysqld = new Test_mysqld($my_cnf, $opts);
            $this->assertInstanceOf('Test_mysqld', $mysqld, 'Test_mysqldインスタンスではありません');
            if ($mysqld instanceof Test_mysqld) {
                $instances[] = $mysqld;
            }
        }
        $this->assertEquals(2, count($instances), "生成されたインスタンスが３つありません");

        foreach($instances as $mysqld) {
            $mysqld->stop();
        }
        sleep(2);
    }

    public function testNotFound()
    {
        $ref_paths = getenv('PATH');
        putenv("PATH=/foobar");
        $my_cnf = array('skip-networking' => '');
        $opts   = array();
        if (getenv('TEST_MYSQLD_DIR')) {
            $opts['base_dir'] = getenv('TEST_MYSQLD_DIR');
        }

        while(count(Test_mysqld::$SEARCH_PATHS) > 0) {
            array_shift(Test_mysqld::$SEARCH_PATHS);
        }

        $this->assertFalse(isset(Test_mysqld::$ERR_STR[0]), "エラー文言が存在してます");

        try {
            $mysqld = new Test_mysqld($my_cnf, $opts);
            $this->assertFalse(true, "例外が発生していません");
        }
        catch(Exception $e) {
            $this->assertInstanceOf('Exception', $e, "例外が発生していません");
            $this->assertTrue(isset(Test_mysqld::$ERR_STR[0]), "エラー文言が存在してません");
        }
        putenv("PATH={$ref_paths}");
        Test_mysqld::$SEARCH_PATHS = array('/usr/local/mysql');
    }

    public function testMultiProcess()
    {
        $my_cnf = array('skip-networking' => '');
        $opts   = array();
        if (getenv('TEST_MYSQLD_DIR')) {
            $opts['base_dir'] = getenv('TEST_MYSQLD_DIR');
        }

        $path = system('which mysql_install_db');
        rtrim($path, "\n");
        if ($path) {
            $path = preg_replace('/\/[^\/]+\/mysql_install_db/', '', $path);
            if (!in_array($path, Test_mysqld::$SEARCH_PATHS)) {
                Test_mysqld::$SEARCH_PATHS[] = $path;
            }
        }

        $mysqld = new Test_mysqld($my_cnf, $opts);
        $db = new PDO($mysqld->dsn(), $mysqld->user);

        $err = $db->errorInfo();
        $this->assertNull($err[1], "DB接続周りでエラーが発生しています");
    }
}

/*

04-multiprocess.t


ok(DBI->connect($mysqld->dsn), 'check if db is ready');

unless (my $pid = Test::SharedFork::fork) {
    die "fork failed:$!"
        unless defined $pid;
    # child process
    ok(DBI->connect($mysqld->dsn), 'connect from child process');
    exit 0;
}

1 while wait == -1;

ok(DBI->connect($mysqld->dsn), 'connect after child exit');

*/