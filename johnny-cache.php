<?php
/*
Plugin Name: Johnny Cache
Plugin URI: http://emusic.com
Author: Scott Taylor ( wonderboymusic )
Description: UI for managing Batcache / Memcached WP Object Cache backend
Author URI: http://scotty-t.com
Version: 0.3
*/

class JohnnyCache {
    public $get_instance_nonce = 'jc-get_instance';
    public $remove_item_nonce = 'jc-remove_item';
    public $flush_group_nonce = 'jc-flush_group';
    public $get_item_nonce = 'jc-get_item';

	protected static $instance;

	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'wp_ajax_jc-flush-group', array( $this, 'flush_mc_group' ) );
        add_action( 'wp_ajax_jc-remove-item', array( $this, 'remove_mc_item' ) );
        add_action( 'wp_ajax_jc-get-instance', array( $this, 'get_mc_instance' ) );
        add_action( 'wp_ajax_jc-get-item', array( $this, 'get_mc_item' ) );
    }

    public function get_mc_instance() {
        check_ajax_referer( $this->get_instance_nonce, 'nonce' );

        nocache_headers();

        $this->do_instance( $_POST['name'] );
        exit();
    }

    public function get_mc_item() {
        check_ajax_referer( $this->get_item_nonce, 'nonce' );

        nocache_headers();

        $this->do_item( $_POST['key'], $_POST['group'] );
        exit();
    }

    public function flush_mc_group() {
        check_ajax_referer( $this->flush_group_nonce, 'nonce' );

        nocache_headers();

        foreach ( $_POST['keys'] as $key ) {
            wp_cache_delete( $key, $_POST['group'] );
        }
        exit();
    }

    public function remove_mc_item() {
        check_ajax_referer( $this->remove_item_nonce, 'nonce' );

        nocache_headers();

        wp_cache_delete( $_POST['key'], $_POST['group'] );
        exit();
    }

    public function admin_menu() {
        $hook = add_menu_page(
			__( 'Johnny Cache', 'johnny-cache' ),
			__( 'Johnny Cache', 'johnny-cache' ),
            'manage_options',
			'johnny-cache',
			array( $this, 'page' )
		);
        add_action( "load-$hook", array( $this, 'load' ) );
    }

	public function clear_group( $group ) {
		global $wp_object_cache;
		$cleared = 0;
		foreach ( array_keys( $wp_object_cache->mc ) as $name ) {
			$servers = $wp_object_cache->mc[ $name ]->getExtendedStats();
			foreach ( array_keys( $servers ) as $server ) {
				list( $ip, $port ) = explode( ':', $server );
				$list = $this->retrieve_keys( $ip, empty( $port ) ? 11211 : $port );
				foreach ( $list as $item ) {
					if ( strstr( $item, $_GET['cache_group'] . ':' ) ) {
						$wp_object_cache->mc[ $name ]->delete( $item );
						$cleared++;
					}
				}
			}
		}
		return $cleared;
	}

    public function load() {
        if ( ! empty( $_GET['cache_group'] ) ) {
			$cleared = $this->clear_group( $_GET['cache_group'] );

			$url = add_query_arg( array(
				'keys_cleared' => $cleared,
				'cache_cleared' => $_GET['cache_group']

			), menu_page_url( 'johnny-cache', false ) );

            wp_redirect( $url );
            exit();
        }

		$dir = trailingslashit( WP_PLUGIN_URL );
        wp_enqueue_style( 'johnny-cache', $dir . 'johnny-cache/johnny-cache.css' );
        wp_enqueue_script( 'johnny-cache', $dir . 'johnny-cache/johnny-cache.js', array(), '0.3', true );
    }

    public function retrieve_keys( $server, $port = 11211 ) {
        $memcache = new Memcache();
        $memcache->connect( $server, $port );
        $list = array();
        $allSlabs = $memcache->getExtendedStats( 'slabs' );

        foreach ( $allSlabs as $server => $slabs ) {
            foreach ( array_keys( $slabs ) as $slabId ) {
                if ( empty( $slabId ) ) {
					continue;
                }

				$cdump = $memcache->getExtendedStats( 'cachedump', (int) $slabId );
				foreach( $cdump as $arrVal ) {
					if ( ! is_array( $arrVal ) ) {
						continue;
					}
					foreach( array_keys( $arrVal ) as $k ) {
						$list[] = $k;
					}
				}
            }
        }
        return $list;
    }

    public function do_item( $key, $group ) {
        $value = wp_cache_get( $key, $group );
        $cache = is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
        printf(
			'<textarea class="widefat" rows="10" cols="35">%s</textarea>',
			esc_html( $cache )
		);
    }

    public function do_instance( $server ) {
        $flush_group_nonce = wp_create_nonce( $this->flush_group_nonce );
        $remove_item_nonce = wp_create_nonce( $this->remove_item_nonce );
        $get_item_nonce = wp_create_nonce( $this->get_item_nonce );

        $blog_id = 0;
        $list = $this->retrieve_keys( $server );

		$keymaps = array(); ?>
        <table borderspacing="0" id="cache-<?php echo sanitize_title( $server ) ?>">
            <tr><th>Blog ID</th><th>Cache Group</th><th>Keys</th></tr>
        <?php
            foreach ( $list as $item ) {
                $parts = explode( ':', $item );
                if ( is_numeric( $parts[0] ) ) {
                    $blog_id = array_shift( $parts );
                    $group = array_shift( $parts );
                } else {
                    $group = array_shift( $parts );
                    $blog_id = 0;
                }

                if ( count( $parts ) > 1 ) {
                    $key = join( ':', $parts );
                } else {
                    $key = $parts[0];
                }
                $group_key = $blog_id . $group;
                if ( isset( $keymaps[$group_key] ) ) {
                    $keymaps[$group_key][2][] = $key;
                } else {
                    $keymaps[$group_key] = array( $blog_id, $group, array( $key ) );
                }
            }
            ksort( $keymaps );
            foreach ( $keymaps as $group => $values ) {
                list( $blog_id, $group, $keys ) = $values;

                $group_link = empty( $group ) ? '' : sprintf(
                    '%s<p><a class="button jc-flush-group" href="/wp-admin/admin-ajax.php?action=jc-flush-group&blog_id=%d&group=%s&nonce=%s">Flush Group</a></p>',
                    $group, $blog_id, $group, $flush_group_nonce
                );

                $key_links = array();
                foreach ( $keys as $key ) {
                    $fmt = '<p data-key="%1$s">%1$s ' .
                        '<a class="jc-remove-item" href="/wp-admin/admin-ajax.php?action=jc-remove-item&key=%1$s&blog_id=%2$d&group=%3$s&nonce=%4$s">Remove</a>' .
                        ' <a class="jc-view-item" href="/wp-admin/admin-ajax.php?action=jc-get-item&key=%1$s&blog_id=%2$d&group=%3$s&nonce=%5$s">View Contents</a>' .
                    '</p>';
                    $key_links[] = sprintf(
                        $fmt,
                        $key, $blog_id, $group, $remove_item_nonce, $get_item_nonce
                    );
                }

                printf(
                    '<tr><td class="td-blog-id">%d</td><td class="td-group">%s</td><td>%s</td></tr>',
                    $blog_id,
                    $group_link,
                    join( '', array_values( $key_links ) )
                );
            }
        ?>
        </table>
    <?php
    }

    public function page() {
        global $wp_object_cache;
        $get_instance_nonce = wp_create_nonce( $this->get_instance_nonce );
    ?>
    <div class="wrap johnny-cache" id="jc-wrapper">
        <h2>Johnny Cache</h2>

        <?php
        if ( isset( $_GET['cache_cleared'] ) ) {
            printf(
                '<p><strong>%s</strong>! Cleared <strong>%d</strong> keys from the cache group: %s</p>',
                __( 'DONE' ),
                isset( $_GET['keys_cleared'] ) ? (int) $_GET['keys_cleared'] : 0,
                isset( $_GET['cache_cleared'] ) ? $_GET['cache_cleared'] : 'none returned'
            );
        }

		if ( empty( $wp_object_cache->mc ) ): ?>
		<p>You are not using Memcached.</p>
		<?php else: ?>
			<form action="<?php menu_page_url( 'johnny-cache' ) ?>">
				<p>Clear Cache Group:</p>
				<input type="hidden" name="page" value="johnny-cache" />
				<input type="text" name="cache_group" />
				<button>Clear</button>
			</form>
        <?php
			if ( isset( $_GET['userid'] ) ) {
				$_user = get_user_by( 'id', $_GET['userid'] );
				wp_cache_delete( $_GET['userid'], 'users' );
				wp_cache_delete( $_user->user_login, 'userlogins' );
				$user = get_user_by( 'id', $_GET['userid'] );
				print_r( (array) $user );
			}
        ?>
        <form>
            <p>Enter a User ID:</p>
            <input type="hidden" name="page" value="johnny-cache"/>
            <input type="text" name="userid" />
            <button>Clear Cache for User</button>
        </form>

        <select id="instance-selector" data-nonce="<?php echo $get_instance_nonce ?>">
            <option value="">Select a Memcached instance</option>
        <?php foreach ( array_keys( $wp_object_cache->mc ) as $name ): ?>
            <optgroup label="<?php echo $name ?>">
        <?php
            $servers = $wp_object_cache->mc[ $name ]->getExtendedStats();
            foreach ( array_keys( $servers ) as $server ):
                list( $ip ) = explode( ':', $server ); ?>
                <option value="<?php echo $ip ?>"><?php echo $ip ?></option>
            <?php endforeach ?>
            </optgroup>
        <?php endforeach ?>
        </select>
        <a class="button" id="refresh-instance">Refresh</a>
        <div id="debug"></div>
        <div id="instance-store"></div>
		<?php endif ?>
    </div><?php
    }
}

add_action( 'plugins_loaded', array( 'JohnnyCache', 'get_instance' ) );