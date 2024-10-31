<?php

/*
  Plugin Name: Moving WordPress
  Plugin URI: http://www.prime-strategy.co.id
  Description: Tool for replace URL (domain) in all database's tables
  Author: PT. Prime Strategy Indonesia [Budi Irawan]
  Version: 1.2
  Author URI: http://www.prime-strategy.co.id
  License: GPLv2 or later
 */

// Define file path constants
define( 'PSIWPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSIWPM_FILES_DIR', PSIWPM_PLUGIN_DIR . 'files' );
// Class WP for ZIP
require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );

if ( !class_exists( 'PSI_Move_WP' ) ) {

    class PSI_Move_WP {

        public function __construct() {
            global $wpdb;
            $this->wpdb = &$wpdb;
            add_action( 'admin_menu', array( $this, 'register_menutools' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'loadcss' ) );
            add_action( 'admin_init', array( $this, 'process_post' ) );
        }

        function register_menutools() {
            add_submenu_page( 'tools.php', 'Moving WP Page', 'Moving WP', 'manage_options', 'movewppage', array( $this, 'movewppage_callback' ) );
        }

        function loadcss( $page ) {
            /* Load CSS */
            if ( 'tools_page_movewppage' != $page ) {
                return;
            }
            wp_enqueue_style( 'form_css', plugins_url( 'css/pure-min.css', __FILE__ ) );
        }

        function process_post() {
            /* Form Action */
            if ( isset( $_POST['domove'] ) ) {
                // process $_POST data here
                $this->oldurls = array();
                $this->p = array();
                $this->oldurls[] = get_site_url();
                if ( isset( $_POST['replacetool'] ) ) {
                    foreach ( $_POST['replacetool'] as $val ) {
                        $this->oldurls[] = $val;
                    }
                }
                $this->siteurl = sanitize_text_field( rtrim( $_POST['siteurl'], '/' ) );
                $this->home = sanitize_text_field( rtrim( $_POST['home'], '/' ) );
                foreach ( $this->oldurls as $key => $val ) {
                    $this->p[$key]['se'] = $val;
                    if ( strpos( $val, "s://" ) ) {
                        $this->p[$key]['ch'] = str_replace( "http://", "https://", $this->siteurl );
                    } elseif ( !strpos( $val, "://" ) ) {
                        $this->p[$key]['ch'] = str_replace( "http://", "", $this->siteurl );
                    } else {
                        $this->p[$key]['ch'] = $this->siteurl;
                    }
                }

                if ( $this->siteurl == '' || $this->home == '' ) {
                    add_action( 'admin_notices', array( $this, 'warning_notice' ) );
                    return;
                }
                //-------- Lets Doit --------->>
                array_map( 'unlink', glob( PSIWPM_FILES_DIR . "/backup/*.sql" ) );
                array_map( 'unlink', glob( PSIWPM_FILES_DIR . "/*.zip" ) );
                $this->dumpDB();
                $filename = $this->_create_archive();
                add_action( 'admin_notices', array( $this, 'success_notice' ) );
            } else if ( isset( $_POST['dodownload'] ) ) {
                $this->_donwload();
            }
        }

        function dumpDB() {
            $tablesArray = $this->wpdb->get_results( "SHOW TABLES like '" . $this->wpdb->prefix . "%'", ARRAY_N );
            $tables = array();
            foreach ( $tablesArray as $val ) {
                $tables[] = $val[0];
            }
            $this->_backupdb( $tables );
        }

        private function _backupdb( $tables ) {
            $statements = '';
            foreach ( $tables as $table ) {
                $contents = $this->wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_N ); // Select all from table
                $fields = $this->wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_N ); // Show Column Info
                $num_fields = count( $fields ); // Count column in table
                $createtable = $this->wpdb->get_results( 'SHOW CREATE TABLE ' . $table, ARRAY_N ); // How table create
                $statements.= "\n\n" . $createtable[0][1] . ";\n\n";

                foreach ( $contents as $row ) {
                    $statements.= 'INSERT INTO ' . $table . ' VALUES(';
                    for ( $i = 0; $i < $num_fields; $i++ ) {
                        foreach ( $this->p as $valurl ) {
                            $row[$i] = $this->_changeDomain( $valurl['se'], $valurl['ch'], $row[$i] ); // Process check domain
                        }

                        $row[$i] = addslashes( $row[$i] );
                        $row[$i] = str_replace( array( "\r\n", "\n" ), "\\n", $row[$i] );

                        if ( $row[$i] == '' ) {
                            if ( $fields[$i][2] == 'YES' && is_null( $fields[$i][4] ) ) {
                                $statements.= 'NULL';
                            } else {
                                $statements.= "''";
                            }
                        } else {
                            $statements.= "'" . $row[$i] . "'";
                        }
                        if ( $i < ($num_fields - 1) ) {
                            $statements .= ',';
                        }
                    }
                    $statements.= ");\n";
                }
                $statements.="\n\n\n";
            }

            if ( $this->siteurl !== $this->home ) {
                $home = "'home','" . $this->siteurl;
                $newhome = "'home','" . $this->home;
                $statements = str_replace( $home, $newhome, $statements );
            }
            $file = PSIWPM_FILES_DIR . "/backup/" . DB_NAME . "-" . date( 'Y-m-d-H-i-s' ) . ".sql";
            $handle = fopen( $file, 'w+' );
            fwrite( $handle, $statements );
            fclose( $handle );
        }

        private function _changeDomain( $se, $ch, $string ) {
            if ( $this->_isSerialize( $string ) ) {
                $string = unserialize( $string );
                $string = serialize( $this->recursive_replace( $se, $ch, $string ) );
            } else {
                $string = str_replace( $se, $ch, $string );
            }
            return $string;
        }

        private function _isSerialize( $string ) {
            if ( !is_string( $string ) )
                return;
            $ao = @unserialize( $string );
            return ($ao !== false) ? true : false;
        }

        function recursive_replace( $find, $replace, $data ) {
            if ( is_array( $data ) ) {
                foreach ( $data as $keyr => $valr ) {
                    if ( is_array( $valr ) || is_object( $valr ) ) {
                        $data[$keyr] = $this->recursive_replace( $find, $replace, $valr );
                    } else {
                        if ( is_string( $valr ) ) {
                            $data[$keyr] = str_replace( $find, $replace, $valr ); // Create Array
                        }
                    }
                }
            } elseif ( is_object( $data ) ) {
                foreach ( $data as $keyr => &$valr ) {
                    if ( is_object( $valr ) || is_array( $valr ) ) {
                        $data->$keyr = $this->recursive_replace( $find, $replace, $valr );
                    } else {
                        if ( is_string( $valr ) ) {
                            $data->$keyr = str_replace( $find, $replace, $valr ); // Create Object
                        }
                    }
                }
            } else {
                if ( is_string( $data ) ) {
                    $data = str_replace( $find, $replace, $data );
                }
            }
            return $data;
        }

        private function _create_archive() {
            $filename = PSIWPM_FILES_DIR . '/Backup-' . time() . '.zip';
            $archive = new PclZip( $filename );
            $files = array( PSIWPM_FILES_DIR . '/backup' );
            if ( $archive->create( $files, PCLZIP_OPT_REMOVE_ALL_PATH ) == 0 ) {
                die( 'Error : ' . $archive->errorInfo( true ) );
            }
            $this->filename = PSIWPM_FILES_DIR . "/" . basename( $filename );
        }

        private function _donwload() {
            $this->filename = $_POST['filename'];
            preg_match( '/Backup-(.*).zip$/', $this->filename, $match );
            header( "Cache-Control: public" );
            header( "Content-Description: File Transfer" );
            header( "Content-Length: " . filesize( "$this->filename" ) . ";" );
            header( "Content-Disposition: attachment; filename=$match[0]" );
            header( "Content-Type: application/octet-stream; " );
            header( "Content-Transfer-Encoding: binary" );
            readfile( $this->filename );
        }

        private function _checkurl( $url ) {
            $urls = array();
            if ( strpos( $url, "//localhost" ) ) {
                $urls[] = str_replace( "http:", "https:", $url );
                $urls[] = str_replace( "http://", "", $url );
            } elseif ( strpos( $url, "//www." ) ) {
                $urls[] = str_replace( "//www.", "//", $url );
                $urls[] = str_replace( "http://www.", "https://www.", $url );
                $urls[] = str_replace( "http://www.", "https://", $url );
                $urls[] = str_replace( "http://", "", $url );
                $urls[] = str_replace( "http://www.", "", $url );
            } else {
                $urls[] = str_replace( "//", "//www.", $url );
                $urls[] = str_replace( "http://", "https://www.", $url );
                $urls[] = str_replace( "http://", "https://", $url );
                $urls[] = str_replace( "http://", "www.", $url );
                $urls[] = str_replace( "http://", "", $url );
            }
            return $urls;
        }

        function movewppage_callback() {
            $oldsite = get_option( 'siteurl' );
            $search = $this->_checkurl( $oldsite );
            $isi = '';
            foreach ( $search as $key => $val ) {
                $title = ($key == 0) ? "<label>Replace too possibilities :</label>" : "<label></label>";
                $isi .= "<div class=\"pure-control-group\">
                            $title
                            <input type=\"checkbox\" name=\"replacetool[]\" value=\"$val\" checked>$val
                        </div>";
            }

            echo '<div class="formwrap">
                <h1>
                   Moving WordPress to another domain
                </h1>
                <p>For replace URL (domain) in all database\'s tables.</p>
                <form class="pure-form pure-form-aligned" action="' . $_SERVER['REQUEST_URI'] . '" method="POST">
                <fieldset>
                    <div class="pure-control-group">
                        <label>Current Site URL :</label>
                        <span style="font-weight:bolder">' . $oldsite . '</span>
                    </div>
                    ' . $isi . '
                    <div class="pure-control-group">
                        <label for="siteurl">New Site URL (ex:<span style="background:yellow">http://www.sayonara.com</span>)</label>
                        <input id="siteurl" style="width:30%" type="text" placeholder="Site URL..." name="siteurl" required>
                    </div>
                    <hr>
                     <div class="pure-control-group">
                        <label>Current WordPress URL :</label>
                        <span style="font-weight:bolder">' . get_option( 'home' ) . '</span>
                    </div>
                    <div class="pure-control-group">
                        <label for="home">New WordPress URL</label>
                        <input id="home" style="width:30%" type="text" placeholder="WordPress URL..." name="home" required>
                    </div>
                    <hr>
                    <div class="pure-controls">
                        <button type="submit" class="pure-button pure-button-primary" name="domove">Submit</button>
                    </div>
                </fieldset>
            </form>
            </div>';
        }

        function success_notice() {
            $reqURI = $_SERVER['REQUEST_URI'];
            echo "<div class='updated'>
                 <p>Success , your files in (click for download) :
                 <form action='$reqURI' method='POST'>
                    <input type='hidden' name='filename' value='$this->filename'>
                    <button type='submit' name='dodownload'>$this->filename</button>
                 </form>
                 </p>
                 </div>";
        }

        function warning_notice() {
            echo "<div class='error'>
                 <p>Please fill the form</p>
                 </div>";
        }

    }

}

new PSI_Move_WP();