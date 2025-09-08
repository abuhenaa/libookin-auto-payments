<?php
//die if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Libookin_Auto_Payments {

    /**
     * Instance of the class
     *
     * @var Libookin_Auto_Payments
     */
    private $instance;

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
    }

    /**
     * Get the instance of the class
     *
     * @return Libookin_Auto_Payments
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Init the class
     */
    public function init() {
        //init the class
    }


}