<?php

/**
 * Emulates the Fever API for Tiny Tiny RSS
 */
class Fever extends Plugin
{

    /**
     * @var mixed
     */
    private $host;

    /**
     * @return array
     */
    public function about()
    {
        return array(2.0, 'Emulates the Fever API for Tiny Tiny RSS', 'ricwein & murphy');
    }

    /**
     * @param  mixed $host
     * @return void
     */
    public function init($host)
    {
        $this->host = $host;
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
    }

    /**
     * @param  mixed $method
     * @return bool
     */
    public function before($method)
    {
        return true;
    }

    /**
     * @param  mixed $method
     * @return bool
     */
    public function csrf_ignore($method)
    {
        return true;
    }

    /**
     * @param  string $args
     * @return void
     */
    public function hook_prefs_tab($args)
    {
        if ($args !== 'prefPrefs') {
            return;
        }

        $current_dirs = explode(DIRECTORY_SEPARATOR, dirname(__FILE__));
        $plugin_dir  = array_pop($current_dirs) . '/' . array_pop($current_dirs);

        echo '<div dojoType="dijit.layout.AccordionPane" title="' . __("Fever Emulation") . '">';
        echo '<h3>' . __('Fever Emulation') . '</h3>';
        echo '<p>' . __('Since the Fever API uses a different authentication mechanism to Tiny Tiny RSS, you must set a separate password to login. This password may be the same as your Tiny Tiny RSS password.') . '</p>';
        echo '<p>' . __('Set a password to login with Fever:') . '</p>';

        echo '<form dojoType="dijit.form.Form">';
        echo '<script type="dojo/method" event="onSubmit" args="evt">
			evt.preventDefault();
			if (this.validate()) {
				new Ajax.Request("backend.php", {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
			}
			</script>';
        echo '<input dojoType="dijit.form.TextBox" type="hidden" name="op" value="pluginhandler" />';
        echo '<input dojoType="dijit.form.TextBox" type="hidden" name="method" value="save" />';
        echo '<input dojoType="dijit.form.TextBox" type="hidden" name="plugin" value="fever" />';
        echo '<input dojoType="dijit.form.ValidationTextBox" required="1" type="password" name="password" />';
        echo '<button dojoType="dijit.form.Button" type="submit">' . __('Set Password') . '</button>';
        echo '</form>';

        echo '<p>' . __('To login with the Fever API, set your server details in your favourite RSS application to: ') . '<code>' . get_self_url_prefix() . '/' . $plugin_dir . '/</code></p>';
        echo '<p>' . __('Additional details can be found at ') . '<a href="http://www.feedafever.com/api" target="_blank">http://www.feedafever.com/api</a></p>';
        echo '<p>' . __('Note: Due to the limitations of the API and some RSS clients (for example, Reeder on iOS), some features are unavailable: "Special" Feeds (Published / Tags / Labels / Fresh / Recent), Nested Categories (hierarchy is flattened)') . '</p>';

        echo '</div>';
    }

    /**
     * save new password as declared in
     * http://www.feedafever.com/api
     * @return void
     */
    public function save()
    {
        if (isset($_POST['password']) && isset($_SESSION["uid"])) {
            $result = db_query('SELECT login FROM ttrss_users WHERE id = \'' . db_escape_string($_SESSION['uid']) . '\'');
            if ($line = db_fetch_assoc($result)) {
                $password = md5($line['login'] . ':' . $_POST['password']);
                $this->host->set($this, 'password', $password);
                echo __('Password saved.');
            }
        }
    }

    /**
     * @return void
     */
    public function after()
    {
    }

    /**
     * @return int
     */
    public function api_version()
    {
        return 2;
    }
}
