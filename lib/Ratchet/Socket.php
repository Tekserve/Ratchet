<?php
namespace Ratchet;
use Ratchet\Application\ProtocolInterface;

/**
 * A wrapper for the PHP socket_ functions
 * @author Chris Boden <shout at chrisboden dot ca>
 * @todo Possibly move this into Ratchet\Resource - another concrete could use streams
 */
class Socket implements SocketInterface {
    /**
     * @type resource
     */
    protected $_resource;

    public static $_defaults = array(
        'domain'   => AF_INET
      , 'type'     => SOCK_STREAM
      , 'protocol' => SOL_TCP
    );

    /**
     * @param int Specifies the protocol family to be used by the socket.
     * @param int The type of communication to be used by the socket
     * @param int Sets the specific protocol within the specified domain to be used when communicating on the returned socket
     * @throws Ratchet\Exception
     */
    public function __construct($domain = null, $type = null, $protocol = null) {
        list($domain, $type, $protocol) = static::getConfig($domain, $type, $protocol);

        $this->_resource = @socket_create($domain, $type, $protocol);

        if (!is_resource($this->_resource)) {
            throw new Exception($this);
        }
    }

    public function __destruct() {
        @socket_close($this->_resource);
    }

    public function __toString() {
        $id = (string)$this->getResource();
        return (string)substr($id, strrpos($id, '#') + 1);
    }

    /**
     * @return resource (Socket)
     */
    public function getResource() {
        return $this->_resource;
    }

    /**
     * Calls socket_accept, duplicating its self
     * @throws Exception
     */
    public function __clone() {
        $this->_resource = @socket_accept($this->_resource);

        if (false === $this->_resource) {
            throw new Exception($this);
        }
    }

    /**
     * Since PHP is retarded and their golden hammer, the array, doesn't implement any interfaces I have to hackishly overload socket_select
     * @see http://ca3.php.net/manual/en/function.socket-select.php
     * @param Iterator|array|NULL The sockets listed in the read array will be watched to see if characters become available for reading (more precisely, to see if a read will not block - in particular, a socket resource is also ready on end-of-file, in which case a socket_read() will return a zero length string).
     * @param Iterator|array|NULL The sockets listed in the write array will be watched to see if a write will not block.
     * @param Iterator|array|NULL The sockets listed in the except array will be watched for exceptions.
     * @param int The tv_sec and tv_usec together form the timeout parameter. The timeout is an upper bound on the amount of time elapsed before socket_select() return. tv_sec may be zero , causing socket_select() to return immediately. This is useful for polling. If tv_sec is NULL (no timeout), socket_select() can block indefinitely.
     * @param int
     * @throws \InvalidArgumentException
     * @throws Exception
     */
    public function select(&$read, &$write, &$except, $tv_sec, $tv_usec = 0) {
        $read   = static::mungForSelect($read);
        $write  = static::mungForSelect($write);
        $except = static::mungForSelect($except);

        $num = socket_select($read, $write, $except, $tv_sec, $tv_usec);

        if (false === $num) {
            throw new Exception($this);
        }

        return $num;
    }

    /**
     * @todo Do loop to make sure entire buffer is sent to client
     */
    public function write($buffer, $length = 0) {
        return $this->__call('write', array($buffer, $length));
    }

    public function close() {
        return $this->__call('close', array());
    }

    /**
     * @see http://ca3.php.net/manual/en/function.socket-recv.php
     * @param string Variable to write data to
     * @param int Number of bytes to read
     * @param int
     * @return int Number of bytes received
     * @throws Exception
     */
    public function recv(&$buf, $len, $flags) {
        if (false === ($bytes = @socket_recv($this->_resource, $buf, $len, $flags))) {
            throw new Exception($this);
        }

        return $bytes;
    }

    /**
     * @param Ratchet\Application\ProtocolInterface
     * @return Socket
     * @throws Exception
     */
    public static function createFromConfig(ProtocolInterface $protocol) {
        $config = $protocol::getDefaultConfig();
        $class  = get_called_class();

        $socket = new $class($config['domain'] ?: null, $config['type'] ?: null, $config['protocol'] ?: null);

        if (is_array($config['options'])) {
            foreach ($config['options'] as $level => $pair) {
                foreach ($pair as $optname => $optval) {
                    $socket->set_option($level, $optname, $optval);
                }
            }
        }

        return $socket;
    }

    /**
     * @internal
     * @param int Specifies the protocol family to be used by the socket.
     * @param int The type of communication to be used by the socket
     * @param int Sets the specific protocol within the specified domain to be used when communicating on the returned socket
     * @return array
     */
    protected static function getConfig($domain = null, $type = null, $protocol = null) {
        foreach (static::$_defaults as $key => $val) {
            if (null === $$key) {
                $$key = $val;
            }
        }

        return array($domain, $type, $protocol);
    }

    /**
     * @internal
     * @param Iterator|array|NULL
     * @return array|NULL
     * @throws \InvalidArgumentException
     */
    protected static function mungForSelect($collection) {
        if (null === $collection || is_array($collection)) {
            return $collection;
        }

        if (!($collection instanceof \Traversable)) {
            throw new \InvalidArgumentException('Object pass is not traversable');
        }

        $return = array();
        foreach ($collection as $key => $socket) {
            $return[$key] = ($socket instanceof \Ratchet\Socket ? $socket->getResource() : $socket);
        }

        return $return;
    }

    /**
     * Call all the socket_ functions (without passing the resource) through this
     * @see http://ca3.php.net/manual/en/ref.sockets.php
     * @param string
     * @param array
     * @return mixed
     * @throws Exception
     * @throws \BadMethodCallException
     */
    public function __call($method, $arguments) {
        if (function_exists('socket_' . $method)) {
            // onBeforeMethod

            array_unshift($arguments, $this->_resource);
            $result = @call_user_func_array('socket_' . $method, $arguments);

            if (false === $result) {
                throw new Exception($this);
            }

            // onAfterMethod

            return $result;
        }

        throw new \BadMethodCallException("{$method} is not a valid socket function");
    }
}