<?php
/*
Plugin Name: Certify Post
Plugin URI: http://easytimestamping.com
Description: Create strong evidence of intellectual property of your posts and show it to your viewers.
Author: Marco Rucci
Version: 0.2
Author URI: http://securo.it
*/

$ezts_api_url='https://api.securo.it'
#$ezts_api_url='http://api.localhost';
$ezts_client_string='ezts-wp:0.2';

$ezts_log_enable = false;
$ezts_cheap_param = 'false'; // toggle fake timestamping for debugging purposes

#ezts_log(PHP_EOL);
#ezts_log('ezts-wp is being read.');
#ezts_api_timestamp('test-text-' . hash('sha256', rand(1, 1000000)), 'test-name');

function ezts_log($msg) {
	global $ezts_log_enable;
	if($ezts_log_enable)
		error_log($msg);
}

function ezts_api_sign($route, $ezts_api_secret, $params = array()) {
	ksort($params);
	$msg = $route . (count($params) > 0 ? '?'. http_build_query($params) : '');

	return hash_hmac('sha256', $msg, $ezts_api_secret, false);
}

/** talking about duplication of code...*/
function ezts_api_get_user($keys = null) {
	global $ezts_api_url;
	global $ezts_cheap_param;
	global $ezts_client_string;

	ezts_log(__FUNCTION__ . ' started');

	if($keys === null) {
		$keys = get_option('ezts_options');
	}
	$ezts_api_secret = $keys['ezts_api_secret'];
	$ezts_api_key = $keys['ezts_api_key'];

	$api_route = '/user/' . $ezts_api_key;

	$ezts_post_data = array('client' => $ezts_client_string);
	$ezts_post_data['apiSignature'] = ezts_api_sign($api_route, $ezts_api_secret, $ezts_post_data);

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_URL, $ezts_api_url . $api_route . '?' . http_build_query($ezts_post_data));
	curl_setopt($curl, CURLOPT_POST, false);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_FAILONERROR, true);
	$result = curl_exec($curl);
	if ($result == false) {
		ezts_log('Error: ' . curl_error($curl) . ' - ' . curl_errno($curl));
		curl_close($curl);
		return null;
	}
	curl_close($curl);

	ezts_log('curl result: ' . print_r($result, true));
	$ezts_user = json_decode($result, true);
	ezts_log('user: ' . print_r($ezts_user, true));

	ezts_log(__FUNCTION__ . ' ended');
	return $ezts_user;
}

function ezts_api_timestamp($post_content, $post_name, $keys=null) {
	global $ezts_api_url;
	global $ezts_cheap_param;
	global $ezts_client_string;

	ezts_log(__FUNCTION__ . ' started');

	if($keys === null) {
		$keys = get_option('ezts_options');
	}
	$ezts_api_secret = $keys['ezts_api_secret'];
	$ezts_api_key = $keys['ezts_api_key'];


	$digest = hash('sha256', $post_content);

	$api_route = '/user/' . $ezts_api_key . '/proof/';

	# parameters are alphabetically sorted by the ezts_api_sign function.
	$ezts_post_data = array(
		'cheap' =>  $ezts_cheap_param,
		'from' => 'text',
		'name' => $post_name,
		'text' => $post_content,
	);
	$ezts_post_data['apiSignature'] = ezts_api_sign($api_route, $ezts_api_secret, $ezts_post_data);

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_URL, $ezts_api_url . $api_route);
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $ezts_post_data);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_FAILONERROR, false);
	$result = curl_exec($curl);
	if ($result == false) {
		ezts_log('Error: ' . curl_error($curl) . ' - ' . curl_errno($curl));
	}
	curl_close($curl);

	//ezts_log('Error: ' . curl_error($curl) . ' - ' . curl_errno($curl));

	ezts_log('curl result: ' . print_r($result, true));
	$proof = json_decode($result, true);
	ezts_log('Proof: ' . print_r($proof, true));

	ezts_log(__FUNCTION__ . ' ended');

	return $proof;
}

function ezts_create_notice($type, $msg) {
	$div = '';
	switch($type) {
	case 'error':
		$div = '<div class="error">' . $msg . '</div>';
	break;
	case 'updated':
		$div = '<div class="updated">' . $msg . '</div>';
	break;
	case 'success':
		$div = '<div class="updated" style="background-color: #dff0d8; color: #468847; border-color: #468847;">' . $msg . '</div>';
	break;
	default:
		ezts_log('ERROR: INEXISTENT NOTICE TYPE.');
	}

	return $div;
}

/**************************************/
/* Hooks                              */
/**************************************/

#add_action('publish_post', 'example');
add_action('save_post', 'ezts_save_post_action');
	function ezts_save_post_action($post_ID) {
		ezts_log(__FUNCTION__ . ' started');

		if(wp_is_post_revision( $post_ID ) !== False) {
			ezts_log('Post '.$post_ID.' is a revision.  Abort certification.');
			return;
		}

		$the_post = get_post($post_ID);
		//if(in_array($the_post->post_status, array('trash', 'auto-draft', 'draft'))) {
		if($the_post->post_status !== 'publish') {
			ezts_log('Post '.$post_ID.' has status: ' . $the_post->post_status . '.  Abort certification.');
			return;
		}

		ezts_log('the_post: ' . print_r($the_post, true));

		$old_proof = get_post_meta($the_post->ID, 'ezts_post_proof', true);
		if(!empty($old_proof)) {
			ezts_log('post already has a proof... this is an update!');
			$old_proof = json_decode($old_proof);
		}

		//$proof = null;
		$proof = ezts_api_timestamp($the_post->post_content, $the_post->post_title);

		update_option('ezts_display_post_notices', 1);
		if($proof === false) {
			$msg = ezts_create_notice('error', '<p>An error occured during certification of post content.  Try to update the post later to reexecute the certification process.</p>');
		} else if ($proof['status'] == 'success') {
			$msg = ezts_create_notice('success', '<p>Post certified.  Manage your certifications at <a href="http://easytimestamping.com/#manager" target="_blank">easytimestamping.com</a>.</p>');
			// saving proof in post_meta
			update_post_meta($the_post->ID, 'ezts_post_proof', json_encode($proof));
		} else if ($proof['status'] == 'failure' && ($proof['code'] == 401 || $proof['code'] == 404)) {
			$msg = ezts_create_notice('error', '<p>Post has not been certified.  Check your account setup in the <a href="' . admin_url('options-general.php?page=' . __FILE__) . '">Certified Post options page</a></p>');
		} else if ($proof['status'] == 'failure' && $proof['code'] == 403) {
			$msg = ezts_create_notice('error', '<p>Post has not been certified.  Recharge your account <a href="http://easytimestamping.com/#pricing" target="_blank">easytimestamping.com</a></p>');
		} else {
			$msg = ezts_create_notice('error', '<p>An error occured during certification of post content.  Try to update the post later to reexecute the certification process.</p>');
		}
		update_option('ezts_post_notices', $msg);


		ezts_log(__FUNCTION__ . ' ended');
	}

#add_action('admin_head-post.php', 'ezts_admin_notices'); // called after the redirect
add_action('admin_head', 'ezts_admin_notices'); // called after the redirect
	function ezts_admin_notices() {
		if (get_option('ezts_display_post_notices')) {
			add_action('admin_notices' , create_function( '', "echo '" . get_option('ezts_post_notices') . "';" ) );
			update_option('ezts_display_post_notices', 0);
		}

		/*
		if (get_option('ezts_display_options_notices')) {
			add_action('admin_notices' , create_function( '', "echo '" . get_option('ezts_options_notices') . "';" ) );
			update_option('ezts_display_options_notices', 0);
		}
		*/
	}

add_action('admin_init', 'register_and_build_fields');
	function register_and_build_fields() {
		//register_setting('ezts_options_group', 'ezts_options', 'validate_setting');

		add_settings_section('main_section', '', 'section_cb', __FILE__);
		add_settings_field('ezts_api_key', 'API key:', 'ezts_api_key_option', __FILE__, 'main_section');
		add_settings_field('ezts_api_secret', 'Api Secret:', 'ezts_api_secret_option', __FILE__, 'main_section');
	}

	function section_cb() {}

	function validate_setting($ezts_options) {
		ezts_log('validate options: ' . print_r($ezts_options, true));
		return $ezts_options;
	}

	function ezts_api_key_option() {
		$options = get_option('ezts_options');
		echo "<input name='ezts_options[ezts_api_key]' type='text' value='{$options['ezts_api_key']}' />";
	}

	function ezts_api_secret_option() {
		$options = get_option('ezts_options');
		echo "<input name='ezts_options[ezts_api_secret]' type='text' value='{$options['ezts_api_secret']}' />";
	}


add_action('admin_menu', 'create_theme_options_page');
	function create_theme_options_page() {
		add_options_page('Certified Post Options', 'Certified Post', 'administrator', __FILE__, 'ezts_options_page');
	}

	function ezts_options_page() {
		$options_msg = '';
		if(!get_option('ezts_options')) {
			$options_msg = ezts_create_notice('updated', '<p>Please enter your API keys.  You can find them in <b><a href="http://easytimestamping.com/#useraccount" target="_blank">your account page at easytimestamping.com</a></b></p>');
		}

		if(isSet($_POST['ezts_options_submit'])) {
			//ezts_log('current ezts options: ' . get_option('ezts_options'));
			//ezts_log('_POST: ' . print_r($_POST, true));

			$ezts_options = $_POST['ezts_options'];
			update_option('ezts_options', $ezts_options);
			ezts_log('ezts options "' . implode(array_keys($ezts_options),', ') . '" have been saved.');

			$user = ezts_api_get_user($ezts_options);

			if($user === null) {
				//$options_msg = ezts_create_notice('error', '<p>Error during verification of your easytimestamping keys.  Please <a href="'.$_SERVER['REQUEST_URI'].'">check your settings.</a></p>');
				$options_msg = ezts_create_notice('error', '<p>Error during verification of your easytimestamping keys.  Please check your API keys in <b><a href="http://easytimestamping.com/#useraccount" target="_blank">your account page at easytimestamping.com</a></b></p>');
			} else {
				$options_msg = ezts_create_notice('success', '<p>Great!  You have linked your easytimestamping account (user: <b>'. $user['username'].'</b>) with <b>' . $user['proofs']['remaining'] . '</b> certifications available.</p><p>From now on, every time you publish a new post or update and old one,  the post content will be automatically certified.  Enjoy!</p>');
			}

			/*
			update_option('ezts_display_options_notices', 1);
			if($user === null) {
				update_option('ezts_options_notices', ezts_create_notice('error', '<p>Error during verification of your easytimestamping keys.  Please <a href="'.$_SERVER['REQUEST_URI'].'">check your settings.</a></p>'));
			} else {
				update_option('ezts_options_notices', ezts_create_notice('success', '<p>Great!  You have linked your easytimestamping account (user: '. $user['username'].') with <b>' . $user['proofs']['remaining'] . '</b> certifications available.</p>'));
			}
			*/
		}
	?>
		<style type="text/css">
			#theme-options-wrap {
				width: 700px;
				padding: 3em;
				background: white;
				/*background: -webkit-gradient(linear, left top, left bottom, from(#f4f2f2), color-stop(.2, white), color-stop(.8, #f4f2f2), to(white));*/
				border-top: 1px solid white;
			}

			#theme-options-wrap #icon-tools {
				position: relative;
				top: -10px;
			}

			#theme-options-wrap input[type=text], #theme-options-wrap textarea {
				padding: .7em;
				width: 300px;
			}
		</style>

		<div id="theme-options-wrap" class="widefat">
			<div class="icon32" id="icon-tools"></div>

			<h2>Certified Post Options</h2>
			<?php echo $options_msg ?>

			<form method="post" action="" enctype="multipart/form-data">
				<?php settings_fields('ezts_options_group'); ?>
				<?php do_settings_sections(__FILE__); ?>
				<p class="submit">
					<input name="ezts_options_submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
				</p>
			</form>
		</div>
	<?php
	}

?>
