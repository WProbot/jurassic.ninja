<?php

namespace jn;

if ( ! defined( '\\ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
if ( ! class_exists( 'RationalOptionPages' ) ) {
	require_once __DIR__ . '/RationalOptionPages.php';
}
if ( ! class_exists( 'CustomNameGenerator' ) ) {
	require_once __DIR__ . '/class-customnamegenerator.php';
}

/**
 * Attempts to log debug messages if WP_DEBUG is on and the setting for log_debug_messages is on too.
 */
function debug() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && settings( 'log_debug_messages', false ) ) {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( call_user_func_array( 'sprintf', func_get_args() ) );
		// phpcs:enable
	}
}

require_once __DIR__ . '/rest-api-stuff.php';
require_feature_files();
require_once __DIR__ . '/serverpilot-stuff.php';

define( 'REST_API_NAMESPACE', 'jurassic.ninja' );
define( 'COMPANION_PLUGIN_URL', 'https://github.com/Automattic/companion/archive/master.zip' );
define( 'JETPACK_BETA_PLUGIN_URL', 'https://github.com/Automattic/jetpack-beta/archive/master.zip' );
define( 'SUBDOMAIN_MULTISITE_HTACCESS_TEMPLATE_URL', 'https://gist.githubusercontent.com/oskosk/8cac852c793df5e4946463e2e55dfdd6/raw/a60ce4122a69c1dd36c623c9b999c36c9c8d3db8/gistfile1.txt' );
define( 'SUBDIR_MULTISITE_HTACCESS_TEMPLATE_URL', 'https://gist.githubusercontent.com/oskosk/f5febd1bb65a2ace3d35feac949b47fd/raw/6ea8ffa013056f6793d3e8775329ec74d3304835/gistfile1.txt' );
define( 'REGULAR_SITE_HTACCESS_TEMPLATE_URL', 'https://gist.githubusercontent.com/oskosk/0dab794274742af9caddefbc73f0ad80/raw/504f60da86969a9d55487f0c4821d06928a97218/.htaccess' );

/**
 * Force the site to log the creator in on the first time they visit the site
 * Installs and activates the Jurassic Ninja companion plugin on the site.
 * @param string $password System password for ssh.
 */
function add_auto_login( $password, $sysuser ) {
	$companion_api_base_url = rest_url( REST_API_NAMESPACE );
	$companion_plugin_url = COMPANION_PLUGIN_URL;
	$cmd = 'wp option add auto_login 1'
		. " && wp option add jurassic_ninja_sysuser '$sysuser'"
		. " && wp option add jurassic_ninja_admin_password '$password'"
		. " && wp option add companion_api_base_url '$companion_api_base_url'"
		. " && wp plugin install --force $companion_plugin_url --activate";
	add_filter( 'jurassic_ninja_feature_command', function ( $s ) use ( $cmd ) {
		return "$s && $cmd";
	} );
}

/**
 * Makes sure the site has an .htaccess file
 *
 */
function add_htaccess() {
	$file_url = REGULAR_SITE_HTACCESS_TEMPLATE_URL;
	$cmd = "wget '$file_url' -O .htaccess"
		. " && wp rewrite structure '/%year%/%monthnum%/%day%/%postname%/'"
		. ' && wp rewrite flush';
	add_filter( 'jurassic_ninja_feature_command', function ( $s ) use ( $cmd ) {
		return "$s && $cmd";
	} );
}

/**
 * Just loops through a filtered array of files inside the features directory and requires them
 *
 * @return [type] [description]
 */
function require_feature_files() {
	$available_features = [
		'/features/logged-in-user-email-address.php',
		'/features/content.php',
		'/features/multisite.php',
		'/features/ssl.php',
		'/features/plugins.php',
		'/features/jetpack-beta.php',
		'/features/wc-smooth-generator.php',
		'/features/woocommerce-beta-tester.php',
		'/features/wp-debug-log.php',
		'/features/block-xmlrpc.php',
		'/features/gutenberg-master.php',
		'/features/gutenberg-nightly.php',
		'/features/wordpress-4.php',
	];

	$available_features = apply_filters( 'jurassic_ninja_available_features', $available_features );
	foreach ( $available_features as $feature_file ) {
		require_once PLUGIN_DIR . $feature_file;
	}
	return $available_features;
}

/**
 * Launches a new WordPress instance on the managed server
 * @param  String  $php_version          The PHP version to run the app on.
 * @param  Array   $features             Array of features to enable
 *         boolean config-constants      Should we add the Config Constants plugin to the site?
 *         boolean auto_ssl              Should we add Let's Encrypt-based SSL for the site?
 *         boolean ssl                   Should we add the configured SSL certificate for the site?
 *         boolean gutenberg             Should we add Gutenberg to the site?
 *         boolean jetpack               Should we add Jetpack to the site?
 *         boolean jetpack-beta          Should we add Jetpack Beta Tester plugin to the site?
 *         boolean subdir_multisite      Should we enable subdir-based multisite on the site?
 *         boolean subdir_multisite      Should we enable subdomain-based multisite on the site?
 *         boolean woocommerce           Should we add WooCommerce plugin to the site?
 *         boolean wordpress-beta-tester Should we add Jetpack Beta Tester plugin to the site?
 *         boolean wp-debug-log          Should we set WP_DEBUG and WP_DEBUG log to true ?
 *         boolean wp-log-viewer         Should we add WP Log Viewer plugin to the site?
 * @return Array|Null                    null or the app data as returned by ServerPilot's API on creation.
 */
function launch_wordpress( $php_version = 'default', $requested_features = [] ) {
	$default_features = [
		'shortlife' => false,
	];
	$features = array_merge( $default_features, $requested_features );
	/**
	 * Fired before launching a site, and as soon as we merge feature defaults and requested features
	 *
	 * Alloes to react to requested features in case some condition is not met. e.g. requesting both types of multisite installations.
	 *
	 * @since 3.0
	 *
	 * @param array $features defaults and requested features merged.
	 *
	 */
	do_action( 'jurassic_ninja_do_feature_conditions', $features );

	try {
		// phpcs:disable WordPress.WP.DeprecatedFunctions.generate_random_passwordFound
		$password = generate_random_password();
		// phpcs:enable
		$subdomain = '';
		$collision_attempts = 10;
		do {
			$subdomain = generate_random_subdomain();
			// Add moar randomness to shortlived sites
			if ( $features['shortlife'] ) {
				$subdomain = sprintf( '%s-%s', $subdomain, rand( 2, 500 ) );
			}
		} while ( subdomain_is_used( $subdomain ) && $collision_attempts-- > 0 );
			// title-case the subdomain
			// or default to the classic My WordPress Site
		$site_title = settings( 'use_subdomain_based_wordpress_title', false ) ?
			ucwords( str_replace( '-', ' ', $subdomain ) ) :
			'My WordPress Site';
		/**
		 * Filters the WordPress options for setting up the site
		 *
		 * @since 3.0
		 *
		 * @param array $wordpress_options {
		 *           An array of properties used for setting up the WordPress site for the first time.
		 *           @type string site_title               The title of the site we're creating.
		 *           @type string admin_user               The username for the admin account.
		 *           @type string admin_password           The password or the admin account.
		 *           @type string admin_email              The email address for the admin account.
		 * }
		 */
		$wordpress_options = apply_filters( 'jurassic_ninja_wordpress_options', array(
			'site_title' => $site_title,
			'admin_user' => 'demo',
			'admin_password' => $password,
			'admin_email' => settings( 'default_admin_email_address' ),
		) );
		$domain = sprintf( '%s.%s', $subdomain, settings( 'domain' ) );

		debug( 'Launching %s with features: %s', $domain, implode( ', ', array_keys( array_filter( $features ) ) ) );

		debug( 'Creating sysuser for %s', $domain );

		$user = generate_new_user( $password );

		debug( 'Creating app for %s under sysuser %s', $domain, $user->data->name );

		$app = null;
		// Here PHP Codesniffer parses &$app as if it were a deprecated pass-by-reference but it is not
		// phpcs:disable PHPCompatibility.PHP.ForbiddenCallTimePassByReference.NotAllowed
		/**
		 * Fired for the purpose of launching a site.
		 *
		 * Allows to be hooked so to implement a real site launcher function
		 *
		 * @since 3.0
		 *
		 * @param array $args {
		 *     All we need to describe a php app with WordPress
		 *
		 *     @type object $app                 Passed by reference. This object should contain the resulting data after creating a PHP app.
		 *     @type object $user                An object that is the result of creating a new system user under which the app will run.
		 *     @type string $php_version         The PHP version we're going to use.
		 *     @type string $domain              The domain under which this app will be running.
		 *     @type array  $wordpress_options {
		 *           An array of properties used for setting up the WordPress site for the first time.
		 *           @type string site_title               The title of the site we're creating.
		 *           @type string admin_user               The username for the admin account.
		 *           @type string admin_password           The password or the admin account.
		 *           @type string admin_email              The email address for the admin account.
		 *     }
		 *     $type array $features             The list of features we're going to add to the WordPress installation.
		 * }
		 *
		 */
		do_action_ref_array( 'jurassic_ninja_create_app', [ &$app, $user, $php_version, $domain, $wordpress_options, $features ] );
		// phpcs:enable

		if ( is_wp_error( $app ) ) {
			throw new \Exception( 'Error creating app: ' . $app->get_error_message() );
		}
		log_new_site( $app->data, $features['shortlife'] );

		// Here PHP Codesniffer parses &$app as if it were a deprecated pass-by-reference but it is not
		// phpcs:disable PHPCompatibility.PHP.ForbiddenCallTimePassByReference.NotAllowed
		/**
		 * Allows the enqueueing of commands for features with each launched site.
		 *
		 * This fires before adding the auto login features
		 *
		 * @since 3.0
		 *
		 * @param array $args {
		 *     All we need to describe a php app with WordPress
		 *
		 *     @type object $app                 Passed by reference. This object contains the resulting data after creating a PHP app.
		 *     $type array $features             The list of features we're going to add to the WordPress installation.
		 *     @type string $domain              The domain under which this app will be running.
		 * }
		 *
		 */
		do_action_ref_array( 'jurassic_ninja_add_features_before_auto_login', [ &$app, $features, $domain ] );
		// phpcs:enable

		debug( '%s: Adding .htaccess file', $domain );
		add_htaccess();

		debug( '%s: Adding Companion Plugin for Auto Login', $domain );
		add_auto_login( $password, $user->data->name );

		// Here PHP Codesniffer parses &$app as if it were a deprecated pass-by-reference but it is not
		// phpcs:disable PHPCompatibility.PHP.ForbiddenCallTimePassByReference.NotAllowed
		/**
		 * Allows the enqueueing of commands for features with each launched site.
		 *
		 * This fires after adding the auto login features
		 *
		 * @since 3.0
		 *
		 * @param array $args {
		 *     All we need to describe a php app with WordPress
		 *
		 *     @type object $app                 Passed by reference. This object contains the resulting data after creating a PHP app.
		 *     $type array $features             The list of features we're going to add to the WordPress installation.
		 *     @type string $domain              The domain under which this app will be running.
		 * }
		 *
		 */
		do_action_ref_array( 'jurassic_ninja_add_features_after_auto_login', [ &$app, $features, $domain ] );
		// phpcs:enable

		// Runs the command via SSH
		// The commands to be run are the result of applying the `jurassic_ninja_feature_command` filter
		debug( '%s: Adding features', $domain );
		run_commands_for_features( $user->data->name, $password, $domain );

		debug( 'Finished launching %s', $domain );
		return $app->data;
	} catch ( \Exception $e ) {
		debug( '%s: Error [%s]: %s', $domain, $e->getCode(), $e->getMessage() );
		return null;
	}

}

/**
 * Create a slug from a string
 * @param  string $str       The string to slugify
 * @param  string $delimiter Character to use between words
 * @return string            Slugified version of the string.
 */
function create_slug( $str, $delimiter = '-' ) {
	$slug = strtolower( trim( preg_replace( '/[\s-]+/', $delimiter, preg_replace( '/[^A-Za-z0-9-]+/', $delimiter, preg_replace( '/[&]/', 'and', preg_replace( '/[\']/', '', iconv( 'UTF-8', 'ASCII//TRANSLIT', $str ) ) ) ) ), $delimiter ) );
	return $slug;
}

/**
 * Returns the list of sites that are calculated to have expired
 * @return Array List of sites
 */
function expired_sites() {
	$interval = settings( 'sites_expiration', 'INTERVAL 7 DAY' );
	$interval_shortlived = settings( 'shortlived_sites_expiration', 'INTERVAL 1 HOUR' );
	return db()->get_results(
		"select * from sites where ( last_logged_in IS NOT NULL AND last_logged_in < DATE_SUB( NOW(), $interval ) )
		OR ( last_logged_in is NULL and created < DATE_SUB( NOW(), $interval ) )
		OR ( shortlived and created < DATE_SUB( NOW(), $interval_shortlived ) )",
		\ARRAY_A
	);
}

/**
 * Extends the expiration date for a site
 * @param  string $domain The name of the site.
 * @return [type]         [description]
 */
function extend_site_life( $domain ) {
	db()->update( 'sites',
		[
			'last_logged_in' => current_time( 'mysql', 1 ),
		], [
			'domain' => $domain,
		]
	);
	if ( db()->last_error ) {
		l( db()->last_error );
	};
}

/**
 * Given an array of domains as ServerPilot returns in its API endpoint for an app
 * excludes, the wildcard entries from the array and returns it.
 * @param  Array  $domains The array of domains for an app as returned by ServerPilot's API
 * @return string          The main domain
 */
function figure_out_main_domain( $domains ) {
	$valid = array_filter( $domains, function ( $domain ) {
		return false === strpos( $domain, '*.' );
	} );
	// reset() trick to get first item
	return reset( $valid );
}

/**
 * Generates a new username with a pseudo random name on the managed server.
 * @param  string $password The password to be assigned for the user
 * @return [type]           [description]
 */
function generate_new_user( $password ) {
	$username = generate_random_username();
	$return = null;
	// Here PHP Codesniffer parses &$return as if it were a deprecated pass-by-reference but it is not
	// phpcs:disable PHPCompatibility.PHP.ForbiddenCallTimePassByReference.NotAllowed
	/**
	 * Fired for hooking and actually creating a system user
	 *
	 * This fires before launching a site
	 *
	 * @since 3.0
	 *
	 * @param array $args {
	 *     All we need to describe a system user
	 *
	 *     @type object $return              Passed by reference. This object should container the resulting data after creating a system user.
	 *     @type string $username            The username.
	 *     @type string $password            The password for the user.
	 * }
	 *
	 */
	do_action_ref_array( 'jurassic_ninja_create_sysuser', [ &$return, $username, $password ] );
	// phpcs:enable
	if ( is_wp_error( $return ) ) {
		throw new \Exception( 'Error creating sysuser: ' . $return->get_error_message() );
	}
	return $return;
}

/**
 * Generates a random string of 12 characters.
 * @return string A string with random characters to be used as password for the WordPress administrator
 */
function generate_random_password() {
	$length = 12;
	return wp_generate_password( $length, false, false );
}

/**
 * Generates a random subdomain based on an adjective and sustantive.
 * The words come from:
 *      lib/words/adjectives.txt
 *      lib/words/nouns.txt
 * Tne return value is slugified.
 *
 * @return string A slugified subdomain.
 */
function generate_random_subdomain() {
	$generator = new CustomNameGenerator();
	$name = $generator->getName( settings( 'use_alliterations_for_subdomain', true ) );

	$slug = create_slug( $name );
	return $slug;
}

/**
 * Generates a random username starting with userxxxxx
 * @return string A random username
 */
function generate_random_username() {
	$length = 4;
	return 'user' . bin2hex( random_bytes( $length ) );
}

/**
 * Attempts to log whatever it's feeded by using error_log and printf
 * @param  mixed $stuff  Whatever
 * @return [type]        [description]
 */
function l( $stuff ) {
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
	error_log( print_r( $stuff, true ) );
	// phpcs:enable
}

/**
 * Stores a record for a freshly created site
 * @param  Array $data Site data as returned by ServerPilot's API on creation
 * @return [type]       [description]
 */
function log_new_site( $data, $shortlived = false ) {
	db()->insert( 'sites',
		[
			'username' => $data->name,
			'domain' => figure_out_main_domain( $data->domains ),
			'created' => current_time( 'mysql', 1 ),
			'shortlived' => $shortlived,
		]
	);
	if ( db()->last_error ) {
		l( db()->last_error );
	};
}

/**
 * Stores a record for a purged site
 * @param  Array $data Site data as returned by a query to the sites table
 * @return [type]       [description]
 */
function log_purged_site( $data ) {
	db()->insert( 'purged', [
		'username' => $data['username'],
		'domain' => $data['domain'],
		'created' => $data['created'],
		'last_logged_in' => $data['last_logged_in'],
		'checked_in' => $data['checked_in'],
		'shortlived' => $data['shortlived'],
	] );
	db()->delete( 'sites', [
		'username' => $data['username'],
		'domain' => $data['domain'],
	] );
	if ( db()->last_error ) {
		l( db()->last_error );
	};
}

/**
 * Returns all of the sites managed and created by this instance of Jurassic Ninja
 * @return Array The list of sites
 */
function managed_sites() {
	return db()->get_results( 'select * from sites', \ARRAY_A );
}

/**
 * Updates the record for the site in the sites table indicating
 * that the creator has at least visited wp-admin once (the first time)
 * @param  string $domain The name of the site
 * @return [type]         [description]
 */
function mark_site_as_checked_in( $domain ) {
	db()->update( 'sites',
		[
			'checked_in' => current_time( 'mysql', 1 ),
		], [
			'domain' => $domain,
		]
	);
	if ( db()->last_error ) {
		l( db()->last_error );
	};
}

/**
 * Deletes the system users (and thus the site and its database)
 * for which their sites have been detected as expired, or never used.
 *
 * @return [type] [description]
 */
function purge_sites() {
	$max_sites = 10;
	$sites = sites_to_be_purged();
	// Purge $max_sites at most so the purge task does not interfere
	// with sites creation given that ServerPilot runs tasks in series.
	$sites = array_slice( $sites, 0, $max_sites );
	/**
	 * Filters the array of users listed by ServerPilot
	 *
	 * @param array $users The users returend by serverpilot
	 */
	$system_users = apply_filters( 'jurassic_ninja_sysuser_list', [] );
	if ( is_wp_error( $system_users ) ) {
		debug( 'There was an error fetching users list for purging: (%s) - %s',
			$system_users->get_error_code(),
			$system_users->get_error_message()
		);
		return $system_users;
	}
	$site_users = array_map(
		function ( $site ) {
			return $site['username'];
		},
		$sites
	);
	$purge = array_filter( $system_users, function ( $user ) use ( $site_users ) {
			return in_array( $user->name, $site_users, true );
	} );
	foreach ( $purge as $user ) {
		$return = null;
		// Here PHP Codesniffer parses &$return as if it were a deprecated pass-by-reference but it is not
		// phpcs:disable PHPCompatibility.PHP.ForbiddenCallTimePassByReference.NotAllowed
		/**
		 * Fired for hooking a function that actually deletes a site
		 *
		 * @since 3.0
		 *
		 * @param array $args {
		 *     All we need to delete a site
		 *
		 *     @type object $return     Passed by reference. This object contains the resulting data after deleting a PHP app.
		 *     @type object $user       An object that represents system user under which the app will run.
		 * }
		 *
		 */
		do_action_ref_array( 'jurassic_ninja_delete_site', [ &$return, $user ] );
		// phpcs:enable
		if ( is_wp_error( $return ) ) {
			debug( 'There was an error purging site for user %s: (%s) - %s',
				$user->id,
				$return->get_error_code(),
				$return->get_error_message()
			);
		}
	}
	foreach ( $sites as $site ) {
		log_purged_site( $site );
	}
	return array_map(
		function ( $site ) {
			return $site['domain'];
		},
		$sites
	);
}

/**
 * Runs a command on the manager server using the username and password for
 * a freshly created system user.
 * @param string $user     System user for ssh.
 * @param string $password System password for ssh.
 * @param string $cmd      The command to run on the shell
 * @return string          The command output
 */
function run_command_on_behalf( $user, $password, $cmd ) {
	$domain = settings( 'domain' );
	// Redirect all errors to stdout so exec shows them in the $output parameters
	$run = "SSHPASS=$password sshpass -e ssh -oStrictHostKeyChecking=no $user@$domain '$cmd' 2>&1";
	$output = null;
	$return_value = null;
	// Use exec instead of shell_exect so we can know if the commands failed or not
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
	exec( $run, $output, $return_value );
	// phpcs:enable
	if ( 0 !== $return_value ) {
		debug( 'Commands run finished with code %s and output: %s',
			$return_value,
			implode( ' -> ', $output )
		);
		return new \WP_Error(
			'commands_did_not_run_successfully',
			"Commands didn't run OK"
		);
	}
	return null;
}

/**
 * Runs a set of commands via ssh.
 * The command string is a result of applying filter `jurassic_ninja_feature_command`
 * @param  string $user     [description]
 * @param  string $password [description]
 * @param  string $domain   [description]
 * @return [type]           [description]
 */
function run_commands_for_features( $user, $password, $domain ) {
	$wp_home = "~/apps/$user/public";
	$cmd = "cd $wp_home";
	/**
	 * Filters the string of commands that will be run on behalf of the freshly created user
	 * Use it like this, by concatenating the passed command with && and your command.
	 *
	 *    $cmd = 'wp plugin install whatever --activate';
	 *    add_filter( 'jurassic_ninja_feature_command', function ( $previous_commands ) use ( $mycmd ) {
	 *       return "$previous_commands && $mycmd";
	 *    } );
	 *
	 * @param string $cmd commands chained for running
	 */
	$filter_output = apply_filters( 'jurassic_ninja_feature_command', $cmd );
	debug( '%s: Running commands %s', $domain, $filter_output );
	$return = run_command_on_behalf( $user, $password, $filter_output );
	if ( is_wp_error( $return ) ) {
		throw new \Exception( "Commands didn't run OK" );
	}
	debug( '%s: Commands run OK', $domain );
}

/**
 * Calculates and returns sites that the creator has never visited.
 * @return [type] [description]
 */
function sites_never_checked_in() {
	$interval = settings( 'sites_never_checked_in_expiration', 'INTERVAL 1 HOUR' );
	return db()->get_results( "select * from sites where checked_in is NULL and created < DATE_SUB( NOW(), $interval )", \ARRAY_A );
}

/**
 * Calculates and returns sites on which the creator has never logged in with credentials.
 * The sites include:
 *     expired_sites + sites_never_checked_in + sites_never_logged_in
 *
 * @return Array The list of sites that can be purged.
 */
function sites_to_be_purged() {
	$expired = expired_sites();
	$unused = sites_never_checked_in();
	return array_merge( $expired, $unused );
}

/**
 * Checks if a subdomain is already user by a running site.
 *
 * @param $subdomain    The subdomain to check for collision with an already launched site.
 * @return bool         Return true if the domain is used by a running site.
 */
function subdomain_is_used( $subdomain ) {
	$domain = sprintf( '%s.%s', $subdomain, settings( 'domain' ) );
	$results = db()->get_results( "select * from sites where domain='$domain' limit 1", \ARRAY_A );
	return count( $results ) !== 0;
}
