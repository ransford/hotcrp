<?php

class Dbl {

    const F_RAW = 1;
    const F_APPLY = 2;
    const F_LOG = 4;
    const F_ERROR = 8;

    static public $logged_errors = 0;
    static public $default_dblink;
    static private $error_handler = "Dbl::default_error_handler";

    static function make_dsn($opt) {
        if (isset($opt["dsn"])) {
            if (is_string($opt["dsn"]))
                return $opt["dsn"];
        } else {
            list($user, $password, $host, $name) =
                array(@$opt["dbUser"], @$opt["dbPassword"], @$opt["dbHost"], @$opt["dbName"]);
            $user = ($user !== null ? $user : $name);
            $password = ($password !== null ? $password : $name);
            $host = ($host !== null ? $host : "localhost");
            if (is_string($user) && is_string($password) && is_string($host) && is_string($name))
                return "mysql://" . urlencode($user) . ":" . urlencode($password) . "@" . urlencode($host) . "/" . urlencode($name);
        }
        return null;
    }

    static function sanitize_dsn($dsn) {
        return preg_replace('{\A(\w+://[^/:]*:)[^\@/]+([\@/])}', '$1PASSWORD$2', $dsn);
    }

    static function connect_dsn($dsn) {
        global $Opt;

        $dbhost = $dbuser = $dbpass = $dbname = $dbport = $dbsocket = null;
        if ($dsn && preg_match('|^mysql://([^:@/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[1]);
            $dbname = urldecode($m[2]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[2]);
            $dbuser = urldecode($m[1]);
            $dbname = urldecode($m[3]);
        } else if ($dsn && preg_match('|^mysql://([^:@/]*):([^@/]*)@([^/]*)/(.*)|', $dsn, $m)) {
            $dbhost = urldecode($m[3]);
            $dbuser = urldecode($m[1]);
            $dbpass = urldecode($m[2]);
            $dbname = urldecode($m[4]);
        }
        if (!$dbname || $dbname == "mysql" || substr($dbname, -7) === "_schema")
            return array(null, null);

        if ($dbhost === null)
            $dbhost = ini_get("mysqli.default_host");
        if ($dbuser === null)
            $dbuser = ini_get("mysqli.default_user");
        if ($dbpass === null)
            $dbpass = ini_get("mysqli.default_pw");
        if ($dbport === null)
            $dbport = ini_get("mysqli.default_port");
        if ($dbsocket === null && @$Opt["dbSocket"])
            $dbsocket = $Opt["dbSocket"];
        else if ($dbsocket === null)
            $dbsocket = ini_get("mysqli.default_socket");

        $dblink = new mysqli($dbhost, $dbuser, $dbpass, "", $dbport, $dbsocket);
        if ($dblink && !mysqli_connect_errno() && $dblink->select_db($dbname))
            $dblink->set_charset("utf8");
        else if ($dblink) {
            $dblink->close();
            $dblink = null;
        }
        return array($dblink, $dbname);
    }

    static function set_default_dblink($dblink) {
        self::$default_dblink = $dblink;
    }

    static function set_error_handler($callable) {
        self::$error_handler = $callable ? : "Dbl::default_error_handler";
    }

    static function default_error_handler($dblink, $query) {
        $landmark = caller_landmark(1, "/^Dbl::/");
        trigger_error("$landmark: database error: $dblink->error in $query");
    }

    static private function query_args($args, $flags) {
        $argpos = is_string($args[0]) ? 0 : 1;
        $dblink = $argpos ? $args[0] : self::$default_dblink;
        if ((($flags & self::F_RAW) && count($args) != $argpos + 1)
            || (($flags & self::F_APPLY) && count($args) > $argpos + 2))
            trigger_error(caller_landmark(1, "/^Dbl::/") . ": wrong number of arguments");
        else if (($flags & self::F_APPLY) && @$args[$argpos + 1] && !is_array($args[$argpos + 1]))
            trigger_error(caller_landmark(1, "/^Dbl::/") . ": argument is not array");
        if (count($args) === $argpos + 1)
            return array($dblink, $args[$argpos], array());
        else if ($flags & self::F_APPLY)
            return array($dblink, $args[$argpos], $args[$argpos + 1]);
        else if (count($args) === $argpos + 2 && is_array($args[$argpos + 1])) {
            error_log(caller_landmark(1, "/^Dbl::/") . ": unexpected argument array");
            return array($dblink, $args[$argpos], $args[$argpos + 1]);
        } else
            return array($dblink, $args[$argpos], array_slice($args, $argpos + 1));
    }

    static private function format_query_args($dblink, $qstr, $argv) {
        $original_qstr = $qstr;
        $strpos = $argpos = 0;
        $usedargs = array();
        while (($strpos = strpos($qstr, "?", $strpos)) !== false) {
            // argument name
            $nextpos = $strpos + 1;
            $nextch = substr($qstr, $nextpos, 1);
            if ($nextch === "?") {
                $qstr = substr($qstr, 0, $strpos + 1) . substr($qstr, $strpos + 2);
                $strpos = $strpos + 1;
                continue;
            } else if ($nextch === "{"
                       && ($rbracepos = strpos($qstr, "}", $nextpos + 1)) !== false) {
                $thisarg = substr($qstr, $nextpos + 1, $rbracepos - $nextpos - 1);
                if ($thisarg === (string) (int) $thisarg)
                    --$thisarg;
                $nextpos = $rbracepos + 1;
                $nextch = substr($qstr, $nextpos, 1);
            } else {
                while (@$usedargs[$argpos])
                    ++$argpos;
                $thisarg = $argpos;
            }
            if (!array_key_exists($thisarg, $argv))
                trigger_error(caller_landmark(1, "/^Dbl::/") . ": query '$original_qstr' argument " . (is_int($thisarg) ? $thisarg + 1 : $thisarg) . " not set");
            $usedargs[$thisarg] = true;
            // argument format
            $arg = @$argv[$thisarg];
            if ($nextch === "a" || $nextch === "A") {
                if ($arg === null)
                    $arg = array();
                else if (is_int($arg) || is_string($arg))
                    $arg = array($arg);
                foreach ($arg as $x)
                    if (!is_int($x) && !is_float($x)) {
                        reset($arg);
                        foreach ($arg as &$y)
                            $y = "'" . $dblink->real_escape_string($y) . "'";
                        unset($y);
                        break;
                    }
                if (count($arg) === 0)
                    $arg = ($nextch === "a" ? "=NULL" : " IS NOT NULL");
                else if (count($arg) === 1)
                    $arg = ($nextch === "a" ? "=" : "!=") . $arg[0];
                else
                    $arg = ($nextch === "a" ? " IN (" : " NOT IN (") . join(",", $arg) . ")";
                ++$nextpos;
            } else if ($nextch === "s") {
                $arg = $dblink->real_escape_string($arg);
                ++$nextpos;
            } else {
                if ($arg === null)
                    $arg = "NULL";
                else if (!is_int($arg))
                    $arg = "'" . $dblink->real_escape_string($arg) . "'";
            }
            // combine
            $suffix = substr($qstr, $nextpos);
            $qstr = substr($qstr, 0, $strpos) . $arg . $suffix;
            $strpos = strlen($qstr) - strlen($suffix);
        }
        return $qstr;
    }

    static function format_query(/* [$dblink,] $qstr, ... */) {
        list($dblink, $qstr, $argv) = self::query_args(func_get_args(), 0);
        return self::format_query_args($dblink, $qstr, $argv);
    }

    static private function do_query($args, $flags) {
        list($dblink, $qstr, $argv) = self::query_args($args, $flags);
        if (!($flags & self::F_RAW))
            $qstr = self::format_query_args($dblink, $qstr, $argv);
        $result = $dblink->query($qstr);
        if ($result === true)
            $result = $dblink;
        else if ($result === false && ($flags & (self::F_LOG | self::F_ERROR))) {
            ++self::$logged_errors;
            if ($flags & self::F_ERROR)
                call_user_func(self::$error_handler, $dblink, $qstr);
            else
                error_log(caller_landmark() . ": database error: " . $dblink->error . " in $qstr");
        }
        return $result;
    }

    static function query(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), 0);
    }

    static function query_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW);
    }

    static function query_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY);
    }

    static function q(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), 0);
    }

    static function q_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW);
    }

    static function q_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY);
    }

    static function ql(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), self::F_LOG);
    }

    static function ql_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW | self::F_LOG);
    }

    static function ql_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY | self::F_LOG);
    }

    static function qe(/* [$dblink,] $qstr, ... */) {
        return self::do_query(func_get_args(), self::F_ERROR);
    }

    static function qe_raw(/* [$dblink,] $qstr */) {
        return self::do_query(func_get_args(), self::F_RAW | self::F_ERROR);
    }

    static function qe_apply(/* [$dblink,] $qstr, [$argv] */) {
        return self::do_query(func_get_args(), self::F_APPLY | self::F_ERROR);
    }

    static function free($result) {
        if ($result && $result instanceof mysqli_result)
            $result->close();
    }

}

// number of rows returned by a select query, or 'false' if result is an error
function edb_nrows($result) {
    return $result ? $result->num_rows : false;
}

// next row as an array, or 'false' if no more rows or result is an error
function edb_row($result) {
    return $result ? $result->fetch_row() : false;
}

// array of all rows as arrays
function edb_rows($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[] = $row;
    return $x;
}

// array of all first columns as arrays
function edb_first_columns($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[] = $row[0];
    return $x;
}

// map of all rows
function edb_map($result) {
    $x = array();
    while ($result && ($row = $result->fetch_row()))
        $x[$row[0]] = (count($row) == 2 ? $row[1] : array_slice($row, 1));
    return $x;
}

// next row as an object, or 'false' if no more rows or result is an error
function edb_orow($result) {
    return $result ? $result->fetch_object() : false;
}

// array of all rows as objects
function edb_orows($result) {
    $x = array();
    while ($result && ($row = $result->fetch_object()))
        $x[] = $row;
    return $x;
}

// quoting for SQL
function sqlq($value) {
    return Dbl::$default_dblink->escape_string($value);
}

function sqlq_for_like($value) {
    return preg_replace("/(?=[%_\\\\'\"\\x00\\n\\r\\x1a])/", "\\", $value);
}

function sql_in_numeric_set($set) {
    if (count($set) == 0)
        return "=-1";
    else if (count($set) == 1)
        return "=" . $set[0];
    else
        return " in (" . join(",", $set) . ")";
}

function sql_not_in_numeric_set($set) {
    $sql = sql_in_numeric_set($set);
    return ($sql[0] == "=" ? "!" : " not") . $sql;
}
