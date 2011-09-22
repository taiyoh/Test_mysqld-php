<?php

class Test_mysqld
{
    protected $auto_start = 2;
    protected $base_dir   = null;
    protected $my_cnf     = array();
    protected $mysql_install_db = null;
    protected $mysqld     = null;
    protected $pid        = null;
    protected $_owner_pid = null;

    public static $SEARCH_PATHS = array('/usr/local/mysql');
    public static $ERR_STR      = array();
    
    public function __construct($my_cnf = array(), $opts = array())
    {
        foreach($opts as $attr => $opt) {
            $this->$attr = $opt;
        }

        $this->_owner_pid = getmypid();
        if (isset($opts['base_dir'])) {
            $this->base_dir = (strpos($opts['base_dir'], '/') !== 0)
                ? getcwd() . $opts['base_dir']
                : $opts['base_dir'];
        }
        else {
            $this->base_dir = sys_get_temp_dir();
        }

        $this->my_cnf = array_merge(array(
            'socket'   => $this->base_dir . "/tmp/mysql.sock",
            'datadir'  => $this->base_dir . "/var",
            'pid-file' => $this->base_dir . "/tmp/mysqld.pid"
        ), $my_cnf);

        if (is_null($this->mysql_install_db)) {
            $prog = self::_find_program('mysql_install_db', array('bin', 'scripts'));
            if (!$prog) {
                throw new Exception("no program found: 'mysql_install_db'");
            }
            $this->mysql_install_db = $prog;
        }

        if (is_null($this->mysqld)) {
            $prog = self::_find_program('mysqld', array('bin', 'libexec'));
            if (!$prog) {
                throw new Exception("no program found: 'mysqld'");
            }
            $this->mysqld = $prog;
        }

        if (file_exists($this->my_cnf['pid-file'])) {
            throw new Exception('mysqld is already running (' . $this->my_cnf['pid-file'] . ')');
        }

        if ($this->auto_start) {
            if ($this->auto_start >= 2) {
                $this->setup();
            }
            $this->start();
        }
    }

    public function __destruct()
    {
        if (!is_null($this->pid) && getmypid() == $this->_owner_pid) {
            $this->stop();
        }
    }

    public function getBaseDir()
    {
        return $this->base_dir;
    }

    public function dsn($args = array())
    {
        $host = (isset($this->my_cnf['bind-address']))
            ? $this->my_cnf['bind-address']
            : '127.0.0.1';
        $merged_args = array_merge(array(
            'host'         => $host,
            'port'         => @$this->my_cnf['port'],
            'mysql_socket' => @$this->my_cnf['socket'],
            'user'         => 'root',
            'dbname'       => 'test'
        ), $args);
        ksort($merged_args);
        $dbargs = array();
        foreach($merged_args as $key => $val) {
            if ($val) {
                $dbargs[] = "{$key}={$val}";
            }
        }
        return 'mysql:' . implode(';', $dbargs);
    }

    public function start()
    {
        if (is_null($this->pid)) {
            return;
        }
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('fork failed!');
        }
        else if ($pid) {
            $cmds = array(
                $this->mysqld,
                '--defaults-file=' . $this->base_dir . '/etc/my.cnf',
                '--user=root'
            );
            passthru(implode(' ', $cmds), $ret);
            file_put_contents($this->base_dir . '/tmp/mysqld.log', $ret);
        }
        while (!file_exists($this->my_cnf['pid-file'])) {
            if (waitpid($pid, WNOHANG) > 0) {
                $log = file_get_contents($this->base_dir . '/tmp/mysqld.log');
                die("*** failed to launch mysqld ***\n{$log}");
            }
            usleep(100000);
        }
        $this->pid = $pid;
        $db = new PDO($this->dsn(array('dbname' => 'mysql')));
        $db->exec('CREATE DATABASE IF NOT EXISTS test');
    }

    public function stop($sig = SIGTERM)
    {
        if (is_null($this->pid)) {
            return;
        }
        posix_kill($this->pid, $sig);
        while (pcntl_waitpid($this->pid, $status) <= 0) {}
        $this->pid = null;
        // might remain for example when sending SIGKILL
        unlink($this->my_cnf['pid-file']);
    }

    public function setup()
    {
        // (re)create directory structure
        if (file_exists($this->base_dir)) {
            self::rm_rf($this->base_dir);
        }
        mkdir($this->base_dir, 0777, true);
        foreach(array('etc', 'var', 'tmp') as $subdir) {
            mkdir($this->base_dir . "/$subdir", 0777, true);
        }
        // my.cnf
        $fh = fopen($this->base_dir . '/etc/my.cnf', 'w');
        if (!$fh) {
            throw new Exception("failed to create file:" . $this->base_dir . "/etc/my.cnf");
        }
        fwrite($fh, "[mysqld]\n");
        foreach($this->my_cnf as $key => $val) {
            $line = ((is_null($val))? $key : "{$key}={$val}") . "\n";
            fwrite($fh, $line);
        }
        fclose($fh);
        // mysql_install_db
        if (!file_exists($this->base_dir . '/var/mysql')) {
            $cmd = $this->mysql_install_db;
            // We should specify --defaults-file option first.
            $cmd .= " --defaults-file='" . $this->base_dir . "/etc/my.cnf'";
            $mysql_base_dir = preg_replace('/\/[^\/]+\/mysql_install_db$/', '', $this->mysql_install_db);
            if ($mysql_base_dir) {
                $cmd .= " --basedir='{$mysql_base_dir}'";
            }
            $cmd .= " 2>&1";
            if (system($cmd, $output) === false) {
                die("*** mysql_install_db failed ***\n{$output}\n");
            }
        }
    }

    protected function _find_program($prog, $subdirs)
    {
        $path = self::_get_path_of($prog);
        if ($path) {
            return $path;
        }
        $path  = null;
        $paths = array(self::_get_path_of('mysql'));
        foreach(self::$SEARCH_PATHS as $_path) {
            $paths[] = "{$_path}/bin/mysql";
        }
        $path_found = false;
        foreach($paths as $mysql) {
            if (is_executable($mysql)) {
                foreach($subdirs as $subdir) {
                    $path = preg_replace('/\/bin\/mysql$/', "/{$subdir}/{$prog}", $mysql);
                    if (is_executable($path)) {
                        $path_found = true;
                        break;
                    }
                    $path = null;
                }
                if (!is_null($path)) {
                    break;
                }
            }
        }
        if (!$path_found) {
            self::$ERR_STR[0] = "could not find {$prog}, please set appropriate PATH";
        }
        return $path;
    }

    protected function _get_path_of($prog)
    {
        $path = system("which {$prog} 2> /dev/null");
        rtrim($path, "\n");
        if (!is_executable($path)) {
            $path = '';
        }
        return $path;
    }

    // via http://linuxserver.jp/%E3%83%97%E3%83%AD%E3%82%B0%E3%83%A9%E3%83%9F%E3%83%B3%E3%82%B0/PHP/%E3%83%87%E3%82%A3%E3%83%AC%E3%82%AF%E3%83%88%E3%83%AA%E3%81%AE%E5%86%8D%E5%B8%B0%E7%9A%84%E5%89%8A%E9%99%A4.php
    protected function rm_rf($dir)
    {
        if (!is_dir($dir)) {
            return false;
        } else {
            $filelist = scandir($dir);
            foreach ($filelist as $filename) {
                if ($filename == '.' || $filename == '..') {
                    continue;
                }
                if (is_dir("{$dir}/{$filename}")) {
                    self::rm_rf("{$dir}/{$filename}");
                } else {
                    unlink("{$dir}/{$filename}");
                }
            }
        }
        rmdir($dir);
        return true;
    }
}
