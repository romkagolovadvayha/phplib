<?php

/**
 * PHP version 5
 *
 * @category  PHP
 * @package   Bb
 * @author    dab <dab@bystrobank.ru>
 * @copyright 2015 ???
 * @license   ???
 * @version   SVN: $Id: fatal_error_handler.php 623 2020-02-25 11:30:36Z dab $
 * @link      http://www.bystrobank.ru
 *
 * обработчик ошибок php
 *
 * перехватывает ошибки времени выполнения самого интерпретатора (неопределенные переменные, индексы и пр.)
 * перехватывает непойманные Exception
 * генерит сообщения об ошибках в соответствии с логикой программы (через trigger_error или FatalErrorException)
 * для parse error, undefined function нужно php>=5.2.4 и display_errors=Off в php.ini для cgi и display_errors=stderr для cli,
 *
 * подключается require_once( "fatal_error_handler.php" );
 * или глобально через php.ini auto_prepend_file = fatal_error_handler.php
 *
 * так как в trigger_error можно передать только один параметр принимается следующее соглашение:
 * строка сообщения начинается символами HTTP4XX - где 4XX http-коды пользовательских ошибок,
 * этот код будет использован в http-заголовке Status, если код не указан будет использован код 550.
 * оставшаяся часть строки так же передается в заголовок Status.
 * если сообщение многострочное в Status передается только первая строка, целиком сообщение будет в теле.
 *
 * в заголовке Status нет понятия charset поэтому сообщения в него выводятся транслитом
 *
 * пример использования:
 * trigger_error("HTTP453 Administrator Access Required\nТребуется доступ с правами администратора");
 *
 * throw new FatalErrorException("Случилось страшное"); //по умолчанию код ошибки будет 550
 *
 * try {
 *   //проверили чтото
 * } catch( Exception $e ) {
 *   // передаем предыдущее исключение $e наверх чтоб отследить трэйс целиком
 *   throw new Exception("Administrator Access Required\nТребуется доступ с правами администратора",453,$e);
 * }
 */

/**
 * обработчик непойманных исключений
 *
 * @param  Exception $exception 1 Самое последнее в жизни исключение увиденное программой
 * @return void                 Обычно из него не возвращаются. Последнее действие которе выполнит скрипт
 */
function uncaughtFatalErrorExceptionHandler($exception, $terminate = true) {
    $http = array_key_exists("SERVER_PROTOCOL", $_SERVER);
    //убейте меня ап стену!
    //$suicide = false;

    $trace1 = PHP_EOL . "=============== BACKTRACE =========================" . PHP_EOL .
            (method_exists($exception, "getPrevious") && $exception->getPrevious() ? $exception->getPrevious()->getTraceAsString() : $exception->getTraceAsString());
    //если использовано памяти меньше четверти (предпологает что в настройках указаны МЕГАБАЙТЫ)
    if (memory_get_usage() < intval(ini_get("memory_limit")) * 1048576 / 8) {
        $trace = PHP_EOL . "=============== FULL BACKTRACE ===================" . PHP_EOL .
                @print_r(method_exists($exception, "getPrevious") && $exception->getPrevious() ? $exception->getPrevious()->getTrace() : $exception->getTrace(), true);
        if (isset($exception->context)) {
            $trace .= PHP_EOL . "=============== CONTEXT VARIABLES ================" . PHP_EOL . @print_r($exception->context, true);
        }
        $get_defined_constants = get_defined_constants(true);
        if (array_key_exists("user", $get_defined_constants)) {
            $trace .= PHP_EOL . "=============== USER DEFINED CONSTANTS ===========" . PHP_EOL . print_r($get_defined_constants["user"], true);
        }
        unset($get_defined_constants);
        $trace .= PHP_EOL . "=============== SERVER VARIABLES ==================" . PHP_EOL . print_r($_SERVER, true);
        if ($http) {
            $lostcontents = "";
            $level = ob_get_level();
            //$len = ob_get_length();
            for ($i = 0; $i < $level; $i++) {
                $lostcontents .= PHP_EOL . PHP_EOL . ob_get_contents();
                ob_end_clean();
            }
            //echo "ob_get_length=".$len;
            if ($lostcontents) {
                $trace .= PHP_EOL . "=============== LOST CONTENTS ====================" . PHP_EOL . $lostcontents . PHP_EOL . "===============" . PHP_EOL;
            }
            unset($lostcontents, $level);
        }
    } else {
        $trace = PHP_EOL . "== FULL BACKTRACE NOT AVAILABLE  (OUT OF MEMORY) ==" . PHP_EOL;
    }
    $message = $exception->getMessage();
    //hide passwords
    $keys = array_keys($_SERVER);
    foreach ($keys as $key) {
        if (is_string($_SERVER[$key]) && strlen($_SERVER[$key]) && preg_match("/^.*_PASSWORD$/", $key)) {
            //в трейсе в параметрах обрезанные строки указаны - их тоже подчищаем
            $trace1 = str_replace(array($_SERVER[$key], substr($_SERVER[$key], 0, 15) . "..."), "****" . $key . "****", $trace1);
            $trace = str_replace(array($_SERVER[$key], substr($_SERVER[$key], 0, 15) . "..."), "****" . $key . "****", $trace);
            $message = str_replace(array($_SERVER[$key], substr($_SERVER[$key], 0, 15) . "..."), "****" . $key . "****", $message);
        }
    }
    unset($keys, $key);
    //E_USER_WARNING может быть брошено более одного в рамках запроса, дополнительно "солим" чтобы ид не пересекались
    $id = "ID_" . $_SERVER["UNIQUE_ID"] . ($terminate == false ? ("_" . uniqid()) : "");
    $code = $exception->getCode();
    $file = $exception->getFile();
    $line = $exception->getLine();
    $user = (isset($_SERVER["USER"]) ? $_SERVER["USER"] : (isset($_SERVER["UID"]) ? $_SERVER["UID"] : posix_getuid()));
    $host = php_uname("n");
    $pid = getmypid();
    $logstr = $user . "@" . $host . ":" . $pid . ":" . $file . ":" . $line . ":" . $code . ":" . $id . ":" . $message;

    //специальная ситуация для исключений внутри обработчика исключений
    //http://bugs.php.net/bug.php?id=32101
    //трейс не нужен - только сообщение
    if ($line == 0 && $file == "Unknown") {
        $trace = "";
    }

    $admin = (array_key_exists("REDIRECT_SERVER_ADMIN", $_SERVER) ? $_SERVER["REDIRECT_SERVER_ADMIN"] : (array_key_exists("SERVER_ADMIN", $_SERVER) ? $_SERVER["SERVER_ADMIN"] : false)); //fastcgi only?
    $logfile = (array_key_exists("REDIRECT_APPLICATION_LOG", $_SERVER) ? $_SERVER["REDIRECT_APPLICATION_LOG"] : (array_key_exists("APPLICATION_LOG", $_SERVER) ? $_SERVER["APPLICATION_LOG"] : false)); //fastcgi only?

    //допустимые пользовательские коды, иначе 550
    if ($code < 450 || $code > 499) {
        $code = 550;
        //дамп пытаемся сохранить отдельным файлом, если неудачно - то во временный каталог - там точно должно хватить прав
        if (@file_put_contents(dirname(get_cfg_var("error_log")) . ($terminate ? "/crash" : "/warn") . $id, $logstr . $trace1 . $trace, FILE_APPEND) === false) {
            @file_put_contents(sys_get_temp_dir() . ($terminate ? "/crash" : "/warn") . $id, $logstr . $trace1 . $trace, FILE_APPEND); //ну нет так нет
        }
        error_log($logstr);
        //оповещаем админа о фатальных ошибках
        //только если это похоже на email. чтоб была возможность указать "none" в SERVER_ADMIN
        if ($admin && strpos($admin, "@")) {
            @mail($admin, ($terminate ? "[PHP FATAL ERROR]" : "[PHP WARNING]") . " " . $id, $logstr . $trace1 .
                            PHP_EOL . "REMOTE_USER=" . (array_key_exists("REMOTE_USER", $_SERVER) ? $_SERVER["REMOTE_USER"] : "") .
                            PHP_EOL . "REMOTE_ADDR=" . (isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : ""), "Content-Type: text/plain; charset=UTF-8; format=flowed");
        }
        //продолжаем выполнение скрипта
        if ($terminate == false) {
            return false;
        }
    } elseif ($http) { //юзеровская ошибка (для cli выводим в strerr никуда не записывая)
        if ($_SERVER["PHP_SELF"][1] == "~") {
            //в отладке дампы юзеровских ошибок тоже сохраним
            if (@file_put_contents(dirname(get_cfg_var("error_log")) . "/error" . $id, $logstr . $trace1 . $trace, FILE_APPEND) === false) {
                if ($logfile && is_writable($logfile)) {
                    error_log("[" . date("d-M-Y H:i:s") . "] " . $logstr . $trace1 . $trace . PHP_EOL, 3, $logfile);
                } else {
                    error_log($logstr . $trace1 . $trace);
                }
            }
        } else {
            //в боевом если указан явно файл - ведем отдельный лог в нем
            if ($logfile && is_writable($logfile)) {
                error_log("[" . date("d-M-Y H:i:s") . "] " . $logstr . PHP_EOL, 3, $logfile);
            } else {
                error_log($logstr);
            }
        }
    }

    //в лог записали сообщение но выводить ничего не нужно
    if ($line == 0 && $file == "Unknown") {
        exit(1);
        return true;
    }

    $reason = "Внутренняя ошибка приложения";
    if ($code == 550) {
        //if (strstr($message, "EXIT")) {
        //   $suicide = true;
        //}
        $hreason = "Internal application error";
        $message = $reason . PHP_EOL . $hreason;
    } else {
        $reason = explode(PHP_EOL, trim($message)); //здесь только первая строка нужна
        $reason = trim($reason[0]);
        //для фатальных ошибок никаких подробностей наружу не показываем
        //выполняем транслитерацию чтобы вписаться в http-заголовок
        //таблица перекодировки из класса Transformer_Translit
        $r = array("щ", "Щ", "ё", "ж", "ч", "ш", "ъ", "ы", "э", "ю", "я", "Ё", "Ж", "Ч", "Ш", "Ъ", "Ы", "Э", "Ю", "Я", "а", "б", "в", "г", "д", "е", "з", "и", "й", "к", "л", "м", "н", "о", "п", "р", "с", "т", "у", "ф", "х", "ц", "ь", "А", "Б", "В", "Г", "Д", "Е", "З", "И", "Й", "К", "Л", "М", "Н", "О", "П", "Р", "С", "Т", "У", "Ф", "Х", "Ц", "Ь");
        $l = array("shh", "Shh", "yo", "zh", "ch", "sh", "``", "y`", "e`", "yu", "ya", "Yo", "Zh", "Ch", "Sh", "``", "Y`", "E`", "Yu", "Ya", "a", "b", "v", "g", "d", "e", "z", "i", "j", "k", "l", "m", "n", "o", "p", "r", "s", "t", "u", "f", "x", "c", "`", "A", "B", "V", "G", "D", "E", "Z", "I", "J", "K", "L", "M", "N", "O", "P", "R", "S", "T", "U", "F", "X", "C", "`");
        $hreason = preg_replace("/[^(\x20-\x7F)]*/", "", str_replace($r, $l, $reason));
        if (strlen($hreason) == 0) {
            $hreason = "Error " . $code;
        }
        unset($r, $l);
    }
    if ($http) {
        if (!headers_sent()) {
            //редирект сбрасываем ДО статуса - так как пыха сама статус для редиректа ставит
            header("Location: ");
            header($_SERVER["SERVER_PROTOCOL"] . " " . $code . " " . $hreason);
            header("Vary: Accept"); //cache-friendly
            //перебиваем заголовки кеширования если они были выставлены скриптом
            header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
            header("Pragma: no-cache");
            header("Last-Modified: ");
            header("Etag: ");
            header("Expires: -1");
            //убиваем указания клиенту куданить перейти
            header("Refresh: ");

            //зачищаем сессию если она была запущена
            if (session_id()) {
                $si = session_get_cookie_params();
                setcookie(session_name(), "", time() - 3600, $si["path"], $si["domain"], $si["secure"], $si["httponly"]);
                //возможно оставить данные сессии для разоброк, надо только идишник сессии сохранить в крашдамп
                session_destroy();
            }
        }
        //проверяем и перекодируем сообщение как нравится клиенту
        $charset = "UTF-8";
        if (array_key_exists("HTTP_ACCEPT_CHARSET", $_SERVER) && !stristr($_SERVER["HTTP_ACCEPT_CHARSET"], $charset)) {
            //тупо берем первый по списку
            $charsets = explode(",", $_SERVER["HTTP_ACCEPT_CHARSET"]);
            //по памяти перебор, зато без эксепшенов которые тут нельзя бросать
            $reason_ = @iconv($charset, $charsets[0], $reason);
            $logstr_ = @iconv($charset, $charsets[0], $logstr);
            $message_ = @iconv($charset, $charsets[0], $message);
            //этот винегрет с проверками чтобы если попали непереводируемые символы или кодировка кривая не обломаться и не выдать кривой ответ
            if (@iconv_strlen($reason, "UTF-8") == @iconv_strlen($reason_, $charsets[0]) &&
                    @iconv_strlen($logstr, "UTF-8") == @iconv_strlen($logstr_, $charsets[0]) &&
                    @iconv_strlen($message, "UTF-8") == @iconv_strlen($message_, $charsets[0])
            ) {
                $reason = $reason_;
                $logstr = $logstr_;
                $message = $message_;
                $charset = $charsets[0];
            }
            unset($charsets, $reason_, $logstr_, $message_);
        }

        //браузеру красивую страничку
        if (array_key_exists("HTTP_ACCEPT", $_SERVER) && strstr($_SERVER["HTTP_ACCEPT"], "html")) {
            if (!headers_sent()) {
                header("Content-type: text/html; charset=" . $charset);
            } else {
                echo PHP_EOL, str_repeat("X", 4096), PHP_EOL, "==========================================", PHP_EOL;
            }
            if (array_key_exists("FATAL_ERROR_HANDLER_HTML_TEMPLATE", $_SERVER) && is_readable($_SERVER["FATAL_ERROR_HANDLER_HTML_TEMPLATE"])) {
                echo str_replace(array("\$REASON", "\$MESSAGE", "\$UNIQUE_ID"), array(htmlspecialchars($reason), htmlspecialchars($message), $id), file_get_contents($_SERVER["FATAL_ERROR_HANDLER_HTML_TEMPLATE"]));
            } else {
                echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">", PHP_EOL,
                "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"ru\">", PHP_EOL,
                "<head>", PHP_EOL,
                "<meta http-equiv=\"content-type\" content=\"text/html; charset=" . $charset . "\"/>", PHP_EOL,
                "<title>", ($code > 499 ? "Fatal" : ""), " error ", $code, "</title>", PHP_EOL,
                "</head>", PHP_EOL,
                "<body>", PHP_EOL,
                "<h2>", htmlspecialchars($reason), "</h2>", PHP_EOL,
                "<hr/>", PHP_EOL,
                "<pre>", PHP_EOL;
                if ($_SERVER["PHP_SELF"][1] == "~") {
                    echo htmlspecialchars($logstr), "<hr/>", $trace1 . $trace;
                } else {
                    echo htmlspecialchars($message);
                }
                echo PHP_EOL,
                "</pre>", PHP_EOL,
                "<hr/>", PHP_EOL,
                "<h6>", ($code > 499 ? "Fatal" : ""), " PHP application error: ", $code, " ", $id, "</h6>", PHP_EOL;
                //echo array_key_exists("SERVER_SIGNATURE",$_SERVER)?"<address>".$_SERVER["SERVER_SIGNATURE"]."<address>":"";
                //if($admin) {
                //echo "<a href=\"mailto:",$admin,"\">",$admin,"</a>";
                //}
                echo "</body>", PHP_EOL,
                "</html>", PHP_EOL;
            }
        } else {
            //остальным просто текст
            if (!headers_sent()) {
                header("Content-type: text/plain; charset=" . $charset);
            } else {
                echo PHP_EOL, str_repeat("X", 4096), PHP_EOL, "==========================================", PHP_EOL;
            }
            echo $message, PHP_EOL, "PHP application error: ", $code, " ", $id, PHP_EOL;
            if ($_SERVER["PHP_SELF"][1] == "~") {
                echo $logstr, PHP_EOL; //трейсы мешают отлаживать ajax и пр. и все равно их там не видно
            }
        }
        //тут начиначется магия!
        //некрасивый ход, но работающий. задумано только для fastcgi! как с другими это обходить неизвестно
        //if( $suicide ) {
        //    error_log("Uncoverable error. Process ".getmypid()." killed.");
        //    //exec("kill ".getmypid()."</dev/null 2>/dev/null 1>/dev/null &");
        //    exec("killall php-cgi");
        //   //posix_kill(getmygid(),SIGKILL);
        //}
    } else {
        //режим командной строки
        if (@file_put_contents("php://stderr", "Error " . $code . " " . $message . PHP_EOL, FILE_APPEND)) {
            file_put_contents("php://stderr", $logstr . PHP_EOL, FILE_APPEND);
            file_put_contents("php://stderr", $trace1, FILE_APPEND);
            file_put_contents("php://stderr", PHP_EOL . "PHP application error1: " . $code . " " . $id . PHP_EOL, FILE_APPEND);
        } else {
            fwrite(STDERR, "Error " . $code . " " . $message . PHP_EOL);
            fwrite(STDERR, $logstr . PHP_EOL);
            fwrite(STDERR, $trace1);
            fwrite(STDERR, PHP_EOL . "PHP application error2: " . $code . " " . $id . PHP_EOL);
        }
        exit($code - 400); //для комстроки диапазоны ошибок впихиваем в 255
    }
    exit(1);
    // Don't execute PHP internal error handler
    return true;
}

/**
 * эксепшен бросаемый при фатальных ошибках
 *
 */
class FatalErrorException extends Exception {

    /**
     * @var mixed контекст окружения
     */
    public $context = null;

    /**
     * @var Exception предыдущее исключение
     */
    private $previous;

    /**
     *
     * @param string $message сообщение
     * @param int $code код ошибки (все кроме пользовательских кодов (450-499) выдают 550, по умолчанию 550)
     * @param Exception $previous передущая ошибка
     * @param array $context переменные контекста (get_defined_vars)
     * @param bool $dontcatchme флаг указывающий ловить или не ловить исключение
     *
     * @return void
     *
     */
    public function __construct($message, $code = 550, $previous = null, $context = null, $dontcatchme = false) {
        if (version_compare(PHP_VERSION, '5.3.0', '<')) {
            parent::__construct($message, $code);
            $this->previous = $previous;
        } else {
            parent::__construct($message, $code, $previous);
        }
        $this->context = $context;
        //$this->trace=array_merge($this->trace,$previous);
        //чтобы нас не поймали try-catch
        if (!$dontcatchme) {
            uncaughtFatalErrorExceptionHandler($this);
        }
    }

    public function setLine($line) {
        $this->line = $line;
    }

    public function setFile($file) {
        $this->file = $file;
    }

    public function _getPrevious() {
        return $this->previous;
    }

    /**
     * http://nooku-framework.svn.sourceforge.net/viewvc/nooku-framework/trunk/code/libraries/koowa/exception/exception.php?revision=2725&view=markup
     * Overloading
     *
     * For PHP < 5.3.0, provides access to the getPrevious() method.
     *
     * @param  string       The function name
     * @param  array        The function arguments
     * @return mixed
     */
    public function __call($method, array $args) {
        if ('getprevious' == strtolower($method)) {
            return $this->_getPrevious();
        }
        return null;
    }

}

/**
 * ловушка для обычных ошибок и конвертер их в эксепшены
 *
 * @param int $error_type php-ные типы ощибок (константы E_*)
 * @param string $message сообщение об ошибке
 * @param string $file файл с ошибкой (__FILE__)
 * @param int $line строка с ошибкой (__LINE__)
 * @param mixed $context контекст с текущими переменными (get_defined_vars())
 *
 * @return void
 *
 */
function convertError2Exception($error_type, $message, $file, $line, $context = null) {
    // if error has been supressed with an @
    if (!(error_reporting() & $error_type)) {
        return true;
    }
    // php5.3 зовет обработчик ошибок при парсинге когда классы еще не загружены :(
    if (!class_exists("FatalErrorException", false)) {
        //восстанавливаем родной обработчик ошибок
        restore_error_handler();
        //эти ошибки связаны со стилем кодирования, считаем их некритичными, продолжаем выполнение
        return false;
    }
    //чтобы пхп-ные коды внутренние не попали на хттпные, но при этом сохранить исходные коды
    $code = 10000000 + $error_type;
    if (!($error_type & E_USER_WARNING) && !strncmp($message, "HTTP4", 5)) {
        $code = (int) substr($message, 4, 3);
        $message = trim(substr($message, 7)); //выгрызть хттп-код из сообщения
    }
    //последним аргументом указываем что щас трапаться не надо - еще не все инициализировано
    $exception = new FatalErrorException($message, $code, null, $context, true);
    $exception->setLine($line);
    $exception->setFile($file);
    //просто передаем эксепшн (исключая брошенные самим обработчиком ошибок)
    if ($error_type & (E_WARNING | E_USER_ERROR) && $line > 0) {
        throw $exception;
    } elseif ($error_type & E_USER_WARNING && $line > 0) {
        //логируем-оповещаем без завершения работы
        uncaughtFatalErrorExceptionHandler($exception, false);
    } else {
        //или явно зовем обработку
        uncaughtFatalErrorExceptionHandler($exception);
    }
}

/**
 * таким способом ловятся фатальные ошибки самого php
 */
function fatalErrorExceptionHandler() {
    //из коментариев к функции set_error_handler nizamgok at gmail dot com 16-Jun-2009 02:27
    if (is_null($e = error_get_last()) === false && (!($e["file"] == __FILE__ || $e["file"] == "xdebug://debug-eval"))) {
        convertError2Exception($e["type"], $e["message"], $e["file"], $e["line"], get_defined_vars());
    }
}

set_error_handler("convertError2Exception", E_ALL);
set_exception_handler("uncaughtFatalErrorExceptionHandler");
register_shutdown_function("fatalErrorExceptionHandler");

/**
 *  эмуляция apache mod_uniqid (для создания идентификатора crash-лога)
 */
if (!array_key_exists("UNIQUE_ID", $_SERVER)) {
    $_SERVER["UNIQUE_ID"] = str_replace(array("+", "/"), array("@", "-"), base64_encode(hex2bin(md5(uniqid(getmypid() . php_uname("n"), true)))));
}

/**
 * для комстроки принудительно включаем вывод ошибок
 */
if (php_sapi_name() == "cli") {
    ini_set("display_errors", "stderr");
//    ini_set("error_log", "/dev/null"); //в cli вывод сообщения в stderr выполняется самим обработчиком ошибок
}

if (!array_key_exists("APPLICATION_LOG", $_SERVER)) {
    $_SERVER["APPLICATION_LOG"] = str_replace(".log", "_4xx.log", get_cfg_var("error_log"));
}

if (!array_key_exists("SERVER_ADMIN", $_SERVER)) {
    $_SERVER["SERVER_ADMIN"] = "root";
}
