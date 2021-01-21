<?php
/**
 * Version 0.3.5
 * This file includes in the global namespace
 * - the base class, ruigehond_[version], for wordpress plugin development
 * - general functionality
 * In the ruigehond_[version] namespace there are some useful classes like the returnObject
 * you should get them using the wrappers in the base class (and hence the derived class)
 */

namespace { // global
    defined( 'ABSPATH' ) or die();

    if ( WP_DEBUG ) { // When debug display the errors generated during activation (if any).
        if (function_exists('ruigehond_activation_error') === false) {
            function ruigehond_activation_error()
            {
                update_option('ruigehond_plugin_error', ob_get_contents());
            }

            add_action('activated_plugin', 'ruigehond_activation_error');
            /* Then to display the error message: */
            echo get_option('ruigehond_plugin_error');
            /* Remove or it will persist */
            delete_option('ruigehond_plugin_error');
        }
    }

    /**
     * Base class for plugin development, contains useful methods and variables, inherit from this in your plugin
     */
    Class ruigehond_0_3_5 {
        public $identifier, $wpdb;
        private $options, $options_checksum;

        public function __construct( $identifier ) {
            $this->identifier = $identifier; // must be ruigehond###, the unique identifier for this plugin
            global $wpdb;
            $this->wpdb = $wpdb;
            // @since 0.3.5 moved __destruct to register_shutdown_function (way more stable)
            register_shutdown_function(array($this, '__shutdown'));
        }

        /**
         * called on shutdown, saves changed options for this plugin
         */
        public function __shutdown() {
            // save the options when changed
            if (isset($this->options) and $this->options_checksum !== md5(json_encode($this->options))) {
                update_option($this->identifier, $this->options);
            }
        }

        /**
         * wrapper for answerObject, to get it from the current namespace
         * @param $text
         * @param $data
         * @return \ruigehond_0_3_5\answerObject
         *
         * @since 0.3.3
         */
        public function getAnswerObject($text,$data){
            return new \ruigehond_0_3_5\answerObject($text,$data);
        }

        /**
         * wrapper for questionObject, to get it from the current namespace
         * @param $text
         * @return \ruigehond_0_3_5\questionObject
         */
        public function getQuestionObject($text) {
            return new \ruigehond_0_3_5\questionObject($text);
        }

        /**
         * wrapper for returnObject, to get it from the current namespace
         * @param null $errorMessage
         * @return \ruigehond_0_3_5\returnObject
         */
        public function getReturnObject($errorMessage = null) {
            return new \ruigehond_0_3_5\returnObject($errorMessage);
        }

        /**
         * @param $plugin_slug string official slug of the plugin
         * @return bool whether on (one of) the settings page(s) of this plugin
         * @since 0.3.4
         */
        protected function onSettingsPage($plugin_slug) {
            if ( isset( $_GET['page'] ) and substr( $_GET['page'], 0, strlen($plugin_slug) ) === $plugin_slug ) {
                return \true;
            }
            if (isset($_POST['option_page']) and $_POST['option_page'] === $this->identifier) {
                return \true;
            }
            return \false;
        }



        /**
         * Loads Text Domain for Wordpress plugin
         * NOTE relies on the fact that plugin slug is also the text domain
         * and the .mo files must be in /languages subfolder
         *
         * @param $text_domain string the text domain, which is also the plugin slug as per the rules of Wordpress
         *
         * @since 0.3.1 added correct plugin domain and directory separator, deprecated old version
         */
        public function load_translations($text_domain) {
            $path = $text_domain . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR;
            load_plugin_textdomain( $text_domain, false, $path );
        }

        /**
         * Floating point numbers have errors that make them ugly and unusable that are not simply fixed by round()ing them
         * Use floatForHumans to return the intended decimal as a string (floatVal if you want to perform calculations)
         * Decimal separator is a . as is standard, use numberformatting/ str_replace etc. if you want something else
         *
         * @param $float float a float that will be formatted to be human readable
         *
         * @return string the number is returned as a correctly formatted string
         *
         * @since    0.3.2
         * @added input check in 0.3.3
         */
        function floatForHumans($float) {
            if (is_null($float) or (string)$float === '') return '';
            // floating point not accurate... https://stackoverflow.com/questions/4921466/php-rounding-error
            $rating = strval($float);
            // probably the rounding error (that cannot be fixed with 'round'!) is discarded by strval, but we cleanup anyway
            // whenever there is a series of 0's or 9's, format the number for humans that don't care about computer issues
            if ( $index = strpos( $rating, '00000' ) ) {
                $rating = substr( $rating, 0, $index );
                if ( substr( $rating, - 1 ) === '.' ) {
                    $rating = substr( $rating, 0, - 1 );
                }
            }
            if ( $index = strpos( $rating, '99999' ) ) {
                $rating = substr( $rating, 0, $index );
                if ( substr( $rating, - 1 ) === '.' ) {
                    $rating = strval( intval( $rating ) + 1 );
                } else {
                    $n      = intval( substr( $rating, - 1 ) ); // this can never be nine, so you can add 1 safely
                    $rating = substr( $rating, 0, - 1 ) . strval( $n + 1 );
                }
            }
            return $rating;
        }

        /**
         * since I can't find the Wordpress method for this, this method returns current max upload size
         *
         * @return float current max upload in MB
         */
        public function maxUploadLimit()
        {
            $max_upload = floatval(ini_get('upload_max_filesize'));
            $max_post = floatval(ini_get('post_max_size'));
            $memory_limit = floatval(ini_get('memory_limit'));
            return min($max_upload, $max_post, $memory_limit);
        }

        /**
         * @param $key string the option name you request the value of
         * @param $default mixed|null default null: what to return when key doesn't exist
         *
         * @return mixed|null the requested value or $default when the option doesn't exist
         *
         * @since    0.3.0
         */
        public function getOption($key, $default = null) {
            $options = $this->getOptions();
            if (array_key_exists($key, $options)) {
                return $options[$key];
            } else {
                return $default;
            }
        }

        /**
         * @param $key string the option name you will store the value under
         * @param $value mixed whatever you want to store under the name $key
         *
         * @return mixed|null the old value, should you want to do something with it
         *
         * @since    0.3.0
         */
        public function setOption($key, $value) {
            $return_value = $this->getOption($key);
            // by requesting the old value you are certain $this->options is an array
            $this->options[$key] = $value;
            return $return_value;
        }

        /**
         * Function gets the options for this plugin instance (identified by $this->identifier)
         * using Wordpress get_option and caching the array for further use
         * It also stores a signature, so ruigehond can auto-update the options upon __destruct
         *
         * @return array all the options for $this->identifier as an array, which can be empty
         *
         * @since    0.3.0
         */
        private function getOptions() {
            if (!isset($this->options)) {
                $temp = get_option($this->identifier);
                if ($temp and is_array($temp)) {
                    $this->options = $temp;
                } else {
                    $this->options = array();
                }
                $this->options_checksum = md5(json_encode($this->options));
            }
            return $this->options;
        }

        /**
         * Determines if a post, identified by the specified ID, exists
         * within the WordPress database.
         *
         * @param int $id The ID of the post to check
         *
         * @return   bool          True if the post exists; otherwise, false.
         * @since    0.0.0
         */
        public function post_exists( $id ) {
            return (bool) is_string( get_post_status( $id ) ); // wp function
        }

        /**
         * will execute an insert query and check if it worked, returns the new id if it did, otherwise throws an exception
         *
         * @param string $query valid insert query, is not checked for validity beforehand
         *
         * @return mixed    returns (int) the id of the new row, or false when insert fails
         */
        public function process_insert_return_id( $query ) {
            $rows = $this->wpdb->query( $query ); // returns int rows affected
            if ( $rows === 1 ) {
                return $this->wpdb->insert_id; // var holds the last inserted id
            } else {
                return false;
            }
        }

        /**
         * compares how similar two strings are, when more pretty similar (determined by $similarity), returns true, else false
         * for example: when $similarity is 1, true is only returned when the strings are exactly the same
         * when $similarity is .9: there may be small differences in characters or length and the func still returns true
         *
         * @param string $a first string to compare to second
         * @param string $b second string to compare to first
         * @param float $similarity Optional default 1, provides cutoff point between true and false, between 0 and 1
         *
         * @return boolean, true when the strings
         */
        public function strings_are_similar( $a, $b, $similarity = 1 ) {
            // TODO make the function actually work with $similarity
            return (bool) $a == $b;
        }


        public function has_gutenberg_editor() {
            if ( function_exists( 'is_gutenberg_page' ) && is_gutenberg_page() ) {
                return true;
            } else {
                // This is not gutenberg.
                // This may not even be any editor, you need to check the screen.
                return false;
            }
        }
    }
} // end of global namespace

/**
 * following namespace is used for versioning common objects (/classes)
 * 'get' wrappers for them are in ruigehond_[version] objects
 */
namespace ruigehond_0_3_5 {
    /**
     * A simple object that can be used as a return value for a method or function
     * containing not only a success bit / boolean, but also messages, a question with answers and raw data
     *
     * External variables are public so they are encoded when you put the object through json_encode
     */
    Class returnObject {

        // public vars will be used by json_encode, private ones will not
        public $success, $messages, $question, $data, $has_error;

        /**
         * Constructs the returnObject with a default success value of false
         *
         * @param string $error Optional if you just want to return an error, you can initialize the returnObject as such
         *
         * @since   0.2.0
         */
        public function __construct( $errorMessage = null ) {
            $this->success   = false;
            $this->messages  = [];
            $this->has_error = false;
            if ( isset( $errorMessage ) ) {
                $this->add_message( $errorMessage, 'error' );
            }
        }

        public function get_success() {
            return $this->success;
        }

        /**
         * sets the public $success value of this return object
         *
         * @param boolean $success sets the public $success value (true or false)
         *
         * @since 0.2.0
         */
        public function set_success( $success ) {
            $this->success = (bool) $success;
        }

        /**
         * add message to the public $messages array
         *
         * @param string $messageText Add $string to the messages already in the returnObject
         * @param string $level Optional, default 'log': indicates type of message: 'log', 'warn' or 'error'
         *
         * @since 0.2.0
         */
        public function add_message( $messageText, $level = 'log' ) { // possible levels are 'log', 'warn' and 'error'
            $msg              = new \stdClass;
            $msg->text        = (string) $messageText;
            $msg->level       = (string) $level;
            $this->messages[] = $msg;
            if ( $level === 'error' ) {
                $this->has_error = true;
            }
        }

        public function get_messages() {
            return implode( '\n', $this->messages );
        }

        /**
         * set the returnObject's public $data property
         *
         * @param mixed $data The public $data property will be set to $data, previous values are discarded
         *
         * @since 0.2.0
         */
        public function set_data( $data ) {
            $this->data = $data;
        }

        public function set_question( $question ) {
            if ( isset( $question ) && $question instanceof questionObject ) {
                $this->question = $question;
            }
        }

        public function has_question() {
            return isset( $this->question );
        }


    }

    Class questionObject {
        public $text, $answers;

        public function __construct( $text = null ) {
            $this->text = $text;
        }

        public function add_answer( $answer ) {
            if ( isset( $answer ) && $answer instanceof answerObject ) {
                $this->answers[] = $answer;
            }
        }

        public function set_text( $text ) {
            $this->text = (string) $text;
        }
    }

    Class answerObject {
        public $text, $data;

        public function __construct( $text, $data = null ) {
            $this->text = $text;
            $this->data = $data;
            // if data is null it means javascript doesn't have to send anything back
        }
    }
} // end of namespace ruigehond