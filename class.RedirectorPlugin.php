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
        Signal::connect('mail.processed', function ($obj, &$vars) {

            // Announce to the logs that we are doing stuff. :-)
            $this->log("Received signal from mail.processed from " . get_class($obj));

            // Sanity check before checking
            if (isset($vars['subject']) && $vars['subject']) {
                // Check for forwarding.. 90% of time will not match, so shouldn't do anything.
                $vars = $this->match($vars);
            }
        });
    }

    /**
     * Takes a new message array of variables as constructed by Mail_Parse and MailFetcher
     *
     * Checks to see if it matches the normal "Forwarded Message" attributes..
     *
     * Need to find the earliest sender combo in the array.
     * There could be quite a chain
     * Customer email's Dealer
     * Dealer forwards to Distributor
     * Distributor forwards to Manufacturer
     * Manufacturer internally forwards to Ticketing system
     *
     * ETC..
     *
     * We actually want the Customer one. the First one.
     * Which, in this case, is the LAST one.
     *
     * There is apparently no RFC for forwarded messages (as per: http://stackoverflow.com/a/4743303)
     *
     *
     * @param array $new
     * @return array
     */
    private function match(array $vars)
    {
        // Prepare a copy of the variables.
        $original_variables = $vars;

        // Easier to match without empty lines
        $message_body = Format::stripEmptyLines($vars['message']);

        // Could trim in regex.. but easier to trim here.
        $subject = trim($vars['subject']);

        // Will need the config
        $c = $this->configGet();

        // Build a fancy regex to match domain names
        // Will likely break horribly if they put anything but commas in there.
        $regex_of_allowed_domains = '/.+@(' . str_replace(',', '|', $c->get('domains')) . ')/i';

        // Need to find if the message was forwarded.
        // Use the admin-defined list of domains to build the regex to look for,
        // Then check the subject for "Fwd: ", "[Fwd:...]", "... (fwd)" as per SO link.
        // TODO: Combine $subject matches into one regex, the first is more likely though.
        if (preg_match($regex_of_allowed_domains, $vars['email']) && (preg_match('/^\[?Fwd: /i', $subject) || preg_match('/\(fwd\)$/i', $subject))) {

            // We have a forwarded message (according to the subject)
            $this->log("Matched forwarded subject: $subject");

            // Have to find the original sender
            // Attempting to find this from the body text:
            // // From: SomeName &lt;username@domain.name&gt;
            // The body will be html gibberish though.. have to decode it before checking
            $matchable_body = html_entity_decode($message_body);

            // This should match the text:
            // From: "Name" <"User@Email">
            // Which most forwarded messages seem to have.
            $sender = array();
            if (preg_match_all('/From: (.+) <(.*)>/i', $matchable_body, $sender) != false) {
                return $this->rewrite($vars, $sender);
            } else {
                // Disaster, it is definitely a forwarded message, yet we can't find the details inside it.
                $this->log("Unable to rewrite $subject, No 'From: {name} <{email}>' found.");
            }
        } elseif ($c->get('drupal') && preg_match('/sent a message using the contact form at/', $message_body)) {

            // The message body indicates it could be sent from a Drupal /contact form
            // Luckily the default is PlainText (for Drupal less than 8 at least)
            $this->log("Matched website message.");

            // Attempt to locate the sender in the first line.
            $sender = array();
            if (preg_match_all('#([a-z ]+) \((.*)\) sent a message using the contact form at#i', $message_body, $sender)) {
                return $this->rewrite($vars, $sender);
            }
        }
        // If we got here, we didn't do any rewriting.
        // So, just in case something above did something it shouldn't, let's return the copy we made earlier
        return $original_variables;
    }

    /**
     * Rewrites the variables.
     *
     * The structure we are expecting back from preg_match_all will be a 2-level array $matches
     * $matches[2][..] == 'Email addresses'
     * $matches[1][..] == 'Names'
     * Now, to find the LAST one.. we look at the end of the array, and rewrite the $vars array with the new details.
     *
     * @param array $vars
     * @param array $matches
     * @return array $vars
     */
    private function rewrite(array $vars, array $matches)
    {
        // Check for valid data.
        if (! isset($matches[1][0]) || ! isset($matches[2][0])) {
            return $vars; // Bail.
        }
        // Get the last entry in each array as the proposed "Original Sender" of the first message in the chain.
        $original_name = array_pop($matches[1]);
        $original_email = array_pop($matches[2]);

        // The current details of the soon-to-be-ticket
        $current_sender_name = $vars['name'];
        $current_sender_email = $vars['email'];

        // Verify that the new email isn't the same as the current one, no point rewriting things if it's the same.
        // We can't help you there mate, either it's one big circle-jerk of a forward, or someone's deleted the data we need.
        if ($original_email == $current_sender_email) {
            return $vars;
        }

        // All Good? We're ready!
        // Rewrite the ticket variables with the new data
        $vars['name'] = $original_name;
        $vars['email'] = $original_email;
        $this->log("Rewrote ticket details: $current_sender_name -> {$original_name}");

        // See if admin want's us to add a note about this in the message. (Ticket hasn't been created yet, so can't add an Admin Note to the thread)
        if ($this->getConfig()->get('note')) {

            // Add admin configurable message for note.
            $vars['message'] = $this->getConfig()->get('note-text') . "\n" . $vars['message'];
        }
        return $vars;
    }

    /**
     * Private logging function,
     * Ensures we have permission to log before doing so
     *
     * Logs to the Admin logs, and to the webserver logs.
     *
     * @param unknown $message
     */
    private function log($message)
    {
        global $ost;
        if ($this->configGet()->get('log')) {
            $ost->logDebug("RedirectPlugin", $message);
            error_log("osTicket RedirectPlugin: $message");
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