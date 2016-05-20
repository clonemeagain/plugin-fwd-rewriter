<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to modify tickets as they are being created.
 */
class RedirectorPlugin extends Plugin
{

    var $config_class = 'RedirectorPluginConfig';

    public function bootstrap()
    {
        Signal::connect('mail.processed', function ($obj, $vars) {
            // Sanity check before rewriting..
            if (isset($vars['subject']) && isset($vars['email'])) {
                $vars = $this->rewrite($vars);
            }
        });
    }

    /**
     * Takes a new message array of variables as constructed by Mail_Parse and MailFetcher
     *
     * @param array $new
     * @return array
     */
    private function rewrite(array $new)
    {
        $message_body = Format::stripEmptyLines($new['message']);
        $subject = trim($new['subject']);
        $c = $this->configGet();
        $allowed = $c->get('domains');

        $regex_of_allowed_domains = '/.+@(' . str_replace(',', '|', implode(',', $allowed)) . ')/i';

        // actual sender would be inside the table data: <tr><td>Name</td><td>Some Name</td></tr>
        // actual email would be in the next table row : <tr><td>Email</td><td>Some Email</td></tr>
        if (preg_match($regex_of_allowed_domains, $new['email']) && preg_match('/^Fwd: /i', $subject)) {
            // Fetch actual sender
            $this->log("Matched forwarded subject: $subject");
            $sender = array();
            $matchable_body = html_entity_decode($message_body);
            // Attempting to find: From: sales22 &lt;sales22@germid.cn&gt;
            if (preg_match_all('/From: (.+) <(.*)>/i', $matchable_body, $sender) != false) {
                // Modify the ticket's source to be this person.
                $old_sender = $new['name'];
                if (isset($sender[2][1]) && $sender[2][0] == $new['email']) {
                    // We got several matches.. assume the first is bad.
                    $new['name'] = $sender[1][1];
                    $new['email'] = $sender[2][1];
                    $this->log("Rewrote ticket details: $old_sender -> {$new['name']}");
                    if ($c->get('note')) {
                        $new['message'] = "Ticket modified upon receipt as it was forwarded to us.\n$message_body";
                    }
                } elseif (isset($sender[1][0]) && isset($sender[2][0]) && ! isset($sender[2][1])) {
                    // Overwrite with the only match we found..
                    $new['name'] = $sender[1][0];
                    $new['email'] = $sender[2][0];

                    $this->log("Rewrote ticket details: $old_sender -> {$new['name']}");
                    if ($c->get('note')) {
                        $new['message'] = "Ticket modified upon receipt as it was forwarded to us.\n$message_body";
                    }
                }
            } else {
                $this->log("Unable to rewrite $subject, No From: {name} <{email}> detected in \n\n$message_body.");
            }
        } elseif ($c->get('drupal') && preg_match('/sent a message using the contact form at/', $message_body)) {
            $this->log("Matched website message.");
            $sender = array();
            // Attempt to locate the sender in the first line.
            if (preg_match_all('#([a-z ]+) \((.*)\) sent a message using the contact form at#i', $message_body, $sender)) {
                if (isset($sender[2][0])) {
                    $old_sender = $new['name'];
                    $new['name'] = $sender[1][0];
                    $new['email'] = $sender[2][0];
                    $this->log("Rewrote ticket details: $old_sender -> {$new['name']}");
                    if ($c->get('note')) {
                        $new['message'] = "Ticket modified upon receipt as it was sent from our website.\n\n$message_body";
                    }
                }
            }
        }
        return $new;
    }

    private function log($message)
    {
        global $ost;
        if ($this->configGet()->get('log')) {
            $ost->logDebug("RedirectPlugin", $message);
        }
    }

    /**
     * Required stub.
     *
     * {@inheritDoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall()
    {
        $errors = array();
        parent::uninstall($errors);
    }

    /**
     * Plugin seems to want this.
     */
    public function getForm()
    {
        return array();
    }
}