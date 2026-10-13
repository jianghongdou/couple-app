<?php
namespace App;

use PDO;
use PDOException;

/**
 * MySQL / MariaDB 连接工厂（只负责连接，不负责建表）。
 *
 * 兼容性：只用 PDO MySQL（pdo_mysql），DSN 形如
 *   mysql:host=127.0.0.1;port=3306;dbname=couple;charset=utf8mb4
 *
 * 这里刻意做了几件「宝塔环境」下必须做的事：
 *   1. 连接失败时给出中文、可操作的提示（不会回显密码）
 *   2. 支持「先连服务器（不指定 dbname）」——安装向导建库前要先校验账号密码
 *   3. 兼容 MySQL 5.7 / 8.0 / MariaDB 10.x，不依赖任何 8.0 专有语法
 */
class Database
{
    /**
     * 建立 PDO 连接。
     *
     * @param array $opts host/port/name/user/pass/charset/ssl_ca
     * @param bool  $withDbname false 时不指定 dbname（用于建库前校验账号）
     * @return PDO
     * @throws DbConnectionError
     */
    public static function connect(array $opts, $withDbname = true)
    {
        $host = isset($opts['host']) && $opts['host'] !== '' ? (string) $opts['host'] : '127.0.0.1';
        $port = isset($opts['port']) ? (int) $opts['port'] : 3306;
        if ($port <= 0 || $port > 65535) {
            $port = 3306;
        }
        $name = isset($opts['name']) ? (string) $opts['name'] : '';
        $user = isset($opts['user']) ? (string) $opts['user'] : '';
        $pass = isset($opts['pass']) ? (string) $opts['pass'] : '';
        $charset = isset($opts['charset']) && $opts['charset'] !== ''
            ? (string) $opts['charset'] : Config::DB_CHARSET_DEFAULT;
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $charset)) {
            $charset = Config::DB_CHARSET_DEFAULT;
        }

        if ($user === '') {
            throw new DbConnectionError('MySQL 用户名不能为空，请在安装向导里填写数据库用户名。');
        }

        $parts = ['host=' . $host, 'port=' . $port, 'charset=' . $charset];
        if ($withDbname) {
            if ($name === '') {
                throw new DbConnectionError('MySQL 数据库名不能为空，请在安装向导里填写数据库名。');
            }
            $parts[] = 'dbname=' . $name;
        }
        $dsn = 'mysql:' . implode(';', $parts);

        // 兼容旧版本 PHP 的常量写法（PDO::MYSQL_ATTR_* 在任何版本都存在）
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . $charset;
        }
        $ca = isset($opts['ssl_ca']) ? trim((string) $opts['ssl_ca']) : '';
        if ($ca !== '' && is_file($ca) && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new DbConnectionError(self::explain($e, $host, $port, $name, $withDbname), $e->getMessage());
        }

        // 会话级设置：只要不改服务器全局行为，MySQL 5.7 / 8.0 / MariaDB 都支持
        try {
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            // 某些托管环境不允许修改 sql_mode，忽略即可
        }

        return $pdo;
    }

    /** 数据库服务端版本字符串（例如 10.11.19-MariaDB / 8.0.36） */
    public static function serverVersion(PDO $pdo)
    {
        try {
            return (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException $e) {
            return '';
        }
    }

    /** 数据库类型描述：MySQL / MariaDB（后台信息区块展示用） */
    public static function engineName(PDO $pdo)
    {
        $v = self::serverVersion($pdo);
        if (stripos($v, 'mariadb') !== false) {
            return 'MariaDB';
        }
        return $v === '' ? 'MySQL' : 'MySQL';
    }

    /** 数据库是否已存在（需要连服务器本身查询） */
    public static function databaseExists(array $opts, $name)
    {
        $pdo = self::connect($opts, false);
        $row = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $row->execute([$name]);
        return $row->fetchColumn() !== false;
    }

    /** 建库（幂等）。库名只允许安全字符，避免注入。 */
    public static function createDatabase(array $opts, $name)
    {
        $safe = self::safeIdentifier($name);
        $pdo = self::connect($opts, false);
        $charset = isset($opts['charset']) && $opts['charset'] !== ''
            ? (string) $opts['charset'] : Config::DB_CHARSET_DEFAULT;
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $charset)) {
            $charset = Config::DB_CHARSET_DEFAULT;
        }
        // 不指定 COLLATE：MySQL 8 / 5.7 / MariaDB 的默认排序规则都可用
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safe . '` CHARACTER SET ' . $charset);
        return true;
    }

    /** 库名/表名安全化（只保留字母数字下划线） */
    public static function safeIdentifier($name)
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
        if ($clean === '' || $clean === null) {
            throw new DbConnectionError('数据库名不合法，只允许字母、数字与下划线。');
        }
        return $clean;
    }

    /**
     * 把 PDO 的英文报错翻译成中文、可操作的提示。
     * 注意：绝不把密码拼进提示里。
     */
    public static function explain(PDOException $e, $host, $port, $name = '', $withDbname = true)
    {
        $raw = $e->getMessage();
        $code = (int) $e->getCode();

        if (strpos($raw, '2002') !== false || stripos($raw, "No such host") !== false
            || stripos($raw, 'Connection refused') !== false || stripos($raw, 'getaddrinfo') !== false) {
            return '无法连接数据库服务器 ' . $host . ':' . $port . '（连接被拒绝或主机名不存在）。'
                . '请确认：① MySQL/MariaDB 已启动；② 主机与端口填写正确（宝塔面板「数据库」页面可看到端口）；'
                . '③ 若数据库与网站在同一台机器，主机请填 127.0.0.1。';
        }
        if (strpos($raw, '2005') !== false || stripos($raw, 'Unknown MySQL server host') !== false) {
            return '数据库主机 ' . $host . ' 无法解析。请检查主机名是否写错（本机请用 127.0.0.1）。';
        }
        if (strpos($raw, '1045') !== false || stripos($raw, 'Access denied') !== false) {
            return '数据库账号或密码错误。请到宝塔面板「数据库」里核对用户名与密码，'
                . '并确认该账号有权限访问' . ($withDbname && $name !== '' ? '数据库 ' . $name : '数据库服务器') . '。';
        }
        if (strpos($raw, '1049') !== false || stripos($raw, 'Unknown database') !== false) {
            return '数据库 ' . $name . ' 不存在。请先在宝塔面板新建该数据库，或勾选「数据库不存在时自动创建」。';
        }
        if (strpos($raw, '1044') !== false) {
            return '当前数据库账号没有访问数据库 ' . $name . ' 的权限。请在宝塔面板把该库授权给这个账号。';
        }
        if (strpos($raw, '1043') !== false || stripos($raw, 'Handshake') !== false) {
            return '与数据库的握手失败。请确认 ' . $host . ':' . $port . ' 上运行的是 MySQL/MariaDB，'
                . '并且 PHP 已启用 pdo_mysql 扩展（可能端口填错了，比如填成了网站端口）。';
        }
        if (strpos($raw, 'could not find driver') !== false) {
            return 'PHP 未安装 pdo_mysql 扩展。请在宝塔面板「软件商店 → PHP → 安装扩展」中安装 pdo_mysql，然后重启 PHP。';
        }
        if ($code === 1045) {
            return '数据库账号或密码错误。请核对宝塔面板里的数据库用户名与密码。';
        }
        return '数据库连接失败（' . self::shortReason($raw) . '）。请检查主机、端口、库名、用户名与密码是否正确。';
    }

    /** 取报错的可读原因（去掉 SQLSTATE 前缀与可能泄漏的细节） */
    public static function shortReason($raw)
    {
        $text = (string) $raw;
        $text = preg_replace('/^SQLSTATE\[[^\]]+\]\s*/', '', $text);
        $text = preg_replace('/\s*\(using password: (YES|NO)\)/i', '', $text);
        if (strlen($text) > 160) {
            $text = substr($text, 0, 160) . '…';
        }
        return $text;
    }
}
