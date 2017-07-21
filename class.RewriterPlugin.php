<?php
require_once (INCLUDE_DIR . 'class.format.php');
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to modify tickets as they are being created.
 * Rewrite or Redirect them from the forwarding user to the actual Owner
 *
 * For instance, using a webform or other to create your tickets via email,
 * the "owner" of a ticket will be whomever email user you sent the email from,
 * not the email address that was entered by the sender.
 *
 * This plugin allows you to rewrite the ticket details transparently to osTicket.
 */
class RewriterPlugin extends Plugin
{

    /**
     * Which config class to load
     *
     * @var string
     */
    var $config_class = 'RewriterPluginConfig';

    /**
     * Set to TRUE to enable webserver logging, and extra logging.
     *
     * @var boolean
     */
    const DEBUG = TRUE;

    /**
     * Turn on to fill your cron logs with every piece of data we have to play with.
     * Great for development/debugging.. not so great in a busy ticket system.
     *
     * @var boolean
     */
    const DUMPWHOLETHING = FALSE;

    /**
     * Hook the bootstrap process, wait for tickets to be created.
     *
     * Run on every instantiation, so needs to be concise.
     *
     * {@inheritdoc}
     *
     * @see Plugin::bootstrap()
     */
    public function bootstrap()
    {
        if (self::DUMPWHOLETHING) {
            $this->log("Bootstrappin..");
        }
        // Listen for new tickets being created:
        Signal::connect('ticket.create.before', function ($obj, &$vars) {
            if (self::DUMPWHOLETHING) {
                $this->log("Received signal ticket.create.before");
                print_r($obj);
                print_r($vars);
            }
            // Only email would send a mail ID.. right? (API can simply set the sender's email manually, the web isn't forwarding.. )
            if (isset($vars['mid'])) {
                $this->process_ticket($vars);
            } elseif (self::DEBUG) {
                $this->log("Ignoring invalid ticket source.");
            }
        });
    }

    /**
     * Takes an array of variables and checks to see if it matches the normal "Forwarded Message" attributes..
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
     * @param array $vars
     */
    private function process_ticket(&$vars)
    {
        // This only works for email.. Tickets created by admins with manually entered "Fwd" stuff,
        // get's filtered by redactor or whatever that is called, so it doesn't work there.
        if (! $vars['message'] instanceof ThreadEntryBody) {
            if (self::DEBUG)
                $this->log("Unable to process ticket, message body wasn't in expected format, this plugin only works for 1.10+. ");
            return;
        }
        
        // Retrieve the text from the ThreadEntryBody, as per v1.10
        $message_body = $vars['message']->getClean();
        
        // Could trim in regex.. but easier to trim here.
        // Only works if the Issue Summary field is a "Short Answer" textbox.. not a choice. :-|
        $subject = trim($vars['subject']);
        if (! $subject) {
            if (self::DEBUG) {
                $this->log("Message had no subject, ignoring.");
            }
            return;
        }
        
        // Build a fancy regex to match domain names
        // Will likely break horribly if they put anything but commas in there.
        // TODO: Make admin option simply "the regex"..? could be good.
        $regex_of_allowed_domains = '/.+@(' . str_replace(',', '|', $this->getConfig()->get('domains')) . ')/i';
        
        // Need to find if the message was forwarded.
        // Use the admin-defined list of domains to build the regex to look for,
        // Then check the subject for "Fwd: ", "[Fwd:...]", "... (fwd)" as per SO link.
        // TODO: Combine $subject matches into one regex, the first is more likely though.
        if (preg_match($regex_of_allowed_domains, $vars['email']) && (preg_match('/^\[?Fwd: /i', $subject) || preg_match('/\(fwd\)$/i', $subject))) {
            
            // We have a forwarded message (according to the subject)
            if (self::DEBUG)
                $this->log("Matched forwarded subject: $subject");
            
            // Have to find the original sender
            // Attempting to find this from the body text:
            // // From: SomeName &lt;username@domain.name&gt;
            // The body will be html gibberish though.. have to decode it before checking
            $matchable_body = $message_body;
            if (self::DUMPWHOLETHING)
                print "Message as parser sees it: \n$matchable_body\n";
            
            // This should match the text:
            // From: "Name" <"User@Email">
            // Which most forwarded messages seem to have, possibly because of
            // https://tools.ietf.org/html/rfc821#page-7
            $sender = array();
            // note, names can have almost anything.. "James O'Brian" etc..
            // strip_tags should leave it with a line like: "From: Name sender@domain.com&gt;"
            // we need to match the word "From:", anything (with spaces around it)
            // followed by something with an @ symbol between it and the string "&gt"
            // Those two things are what we need to rewrite with.
            // Except fucking gmail.. of course:
            // From: <b class="gmail_sendername">Name</b> <span dir="ltr">&lt;name@domain.com&gt;</span>
            // From: <b>Sender Name</b> <span dir="ltr">name@domain.com&gt;</span>
            // wtf is that regex? Where is my will to live? Whyyyy
            // also, how the fuck did I write that?
            // check out regexr.com and play till you get it working.. many complicated!
            // why don't we just match the email address, fuck the name!
            if (preg_match_all('/From:(.*)(?: |<|;|>)([\w\d_\-\.]+@[\w\d_\-\.]+)(?:&|>| )/i', $matchable_body, $sender)) {
                // if (preg_match_all('/From:\s(?:.*>)?(.+)(?:.+?)(?:\s|<|&lt;)([\w\d_\-\.]+@[\w\d_\-\.]+)(?:&gt;|>)/i', $matchable_body, $sender)) {
                return $this->rewrite($vars, $sender);
            } elseif (self::DEBUG) {
                // Disaster, it is definitely a forwarded message, yet we can't find the details inside it.
                $this->log("Unable to rewrite $subject, No 'From: {name} <{email}>' found.");
            }
        } elseif ($this->getConfig()->get('drupal') && preg_match('/sent a message using the contact form at/i', $message_body)) {
            
            // The message body indicates it could be sent from a Drupal /contact form
            // Luckily the default is PlainText (for Drupal 7 and lower at least)
            if (self::DEBUG)
                $this->log("Matched Drupal message.");
            
            // Attempt to locate the sender in the first line.
            // Will fetch the name, then the email which is in (braces)
            $sender = array();
            // TODO: Figure out what this would be for different languages?
            if (preg_match_all('#([\w ]+) \((.*)\) sent a message using the contact form at#i', $message_body, $sender)) {
                return $this->rewrite($vars, $sender);
            }
        }
        
        // See if admin has added any text rewriting rules:
        if ($rules = $this->getConfig()->get('email-rewrite')) {
            $this->rewriteEmail($vars, $rules);
        }
        if ($rules = $this->getConfig()->get('text-rewrite')) {
            $this->rewriteText($vars, $rules);
        }
        if ($rules = $this->getConfig()->get('regex-rewrite')) {
            $this->rewriteTextRegex($vars, $rules);
        }
    }

    /**
     * A little more powerful..
     * it's assumed you know what you're doing when you write a regex.
     * Let's get creative.
     *
     * @param array $vars
     * @param array $rules
     */
    private function rewriteTextRegex($vars, $rules)
    {
        $needles = $replacements = array();
        
        foreach (explode("\n", $rules) as $rule) {
            list ($find, $replace) = explode(':', $rule);
            if (! $find) {
                // skip blank patterns.
                continue;
            }
            
            $replace = $replace ?: ''; // Replace things with nothing if no replacement string.
                                       
            // validate pattern before run
            if (! @preg_match($find, null) === false) {
                $this->log("Pattern $find wasn't a valid regex.");
                continue;
            }
            
            $needles[] = $find;
            $replacements[] = $replace;
        }
        if (self::DUMPWHOLETHING) {
            $this->log("Going to get brutal with regex against the ticket.. hold onto your hat!");
            print_r($needles);
            print_r($replacements);
        }
        
        foreach ($vars as $key => $val) {
            if ($val instanceof ThreadEntryBody) {
                // ie: $vars['message']
                // This will work regardless of the type, ie: Text/Html
                $new_val = preg_replace($needles, $replacements, (string) $val->body);
                // preg_replace error value is null, so if we don't have an error, assume it worked:
                $vars[$key]->body = $new_val ?: $val->body;
            } elseif (is_string($val)) {
                // we can work with text:
                $new_val = preg_replace($needles, $replacements, $val);
                $vars[$key] = $new_val ?: $val;
            }
            // TODO: Decide how deep the rabbit hole we want to go..
            // attachments/recipients/etc are in an array
        }
        
        if (self::DUMPWHOLETHING) {
            $this->log("New vars:");
            print_r($vars);
        }
    }

    private function rewriteText(&$vars, &$rules, $type = 'text')
    {
        foreach (explode("\n", $rules) as $rule) {
            list ($find, $replace) = explode(':', $rule); // PHP 5/7 inverts the order of applying this.. FFS, why?
            
            if (! $find)
                continue;
            
            if (self::DEBUG)
                $this->log("Using $find => $replace text rule on ticket with subject $subject\n");
            
            if ($vars['message'] instanceof ThreadEntryBody && stripos($vars['message']->body, $find) !== FALSE) {
                $vars['message']->body = str_ireplace($find, $replace, (string) $vars['message']->body);
            }
            if (stripos($vars['subject'], $find) !== FALSE) {
                $vars['subject'] = str_ireplace($find, $replace, $vars['subject']);
            }
        }
    }

    private function rewriteEmail(&$vars, &$rules)
    {
        foreach (explode("\n", $rules) as $rule) {
            list ($find, $replace) = explode(':', $rule); // PHP 5/7 inverts the order of applying this.. FFS, why?
            
            if (! $find)
                continue;
            
            if (self::DEBUG)
                $this->log("Using $find => $replace email rule on ticket with subject $subject\n");
            
            {
                if (stripos($vars['email'], $find) !== FALSE) {
                    $vars['email'] = str_ireplace($find, $replace, $vars['email']);
                }
                break;
            }
        }
    }

    /**
     * Rewrites the variables for the new ticket Owner
     *
     * The structure we are expecting back from either preg_match_all will be a 2-level array $matches
     * $matches[1][..] == 'Names'
     * $matches[2][..] == 'Email addresses'
     *
     * Now, to find the LAST one.. we look at the end of the array
     *
     * @param array $vars
     * @param array $matches
     *            (output from preg_match_all with two capture groups name/email in that order)
     */
    private function rewrite(array &$vars, array $matches)
    {
        // Check for valid data.
        if (! isset($matches[1][0]) || ! isset($matches[2][0])) {
            if (self::DEBUG)
                error_log("Unable to match with invalid data, check the regex? check the domains? something borked.");
            
            return; // Bail.
        }
        // Get the last entry in each array as the proposed "Original Sender" of the first message in the chain.
        $original_name = array_pop($matches[1]);
        $original_email = array_pop($matches[2]);
        
        $original_name = strip_tags($original_name); // there's a chance we captured some HTML with the name.. strip it.
                                                     
        // The current details of the soon-to-be-ticket
        $current_sender_name = $vars['name'];
        $current_sender_email = $vars['email']; // We don't validate email because User::fromVars will do it
        
        if (self::DUMPWHOLETHING) {
            print "Going into rewrite with these details:\nOriginal: $current_sender_name <$current_sender_email>\nNew: $original_name <$original_email>\n";
        }
        
        // Verify that the new email isn't the same as the current one, no point rewriting things if it's the same.
        if ($original_email == $current_sender_email) {
            if (self::DEBUG)
                error_log("The forwarded message is from the same person, they forwarded to themselves? bailing");
            return;
        }
        
        // All Good? We're ready!
        // Rewrite the ticket variables with the new data
        // Uses osTicket v1.10 semantics
        $user = User::fromVars(array(
            'name' => $original_name,
            'email' => $original_email
        ));
        if (self::DUMPWHOLETHING) {
            $this->log("Attempted to make/find user for $original_name and $original_email");
            print_r($user); // DEBUG
        }
        if (! $user instanceof User) {
            // Was denied/spammer?
            // We can't use the $user object if it's not a User!
            // Also fails if Registration (user/pass) is required for users and the new one isn't actually a user.
            $this->log("Unable to rewrite to this User $original_email, as we are unable to make an account for them.");
            return;
        }
        $vars['uid'] = $user->getId();
        $this->log("Rewrote ticket details: $current_sender_name -> {$original_name}");
        
        // See if admin want's us to add a note about this in the message. (Ticket hasn't been created yet, so can't add an Admin Note to the thread)
        if ($this->getConfig()->get('note')) {
            $admin_note = $this->getConfig()->get('note-text') . "\n\n";
            $vars['message']->prepend($admin_note); // Another reason to test for ThreadEntryBody, we can use it's methods!
        }
    }

    /**
     * Logging function,
     * Ensures we have permission to log before doing so
     *
     * Attempts to log to the Admin logs, and to the webserver logs if debugging is enabled.
     *
     * @param string $message
     */
    private function log($message)
    {
        global $ost;
        
        // hmm.. might not be available if bootstrapping isn't finished.
        if ((self::DEBUG || $this->getConfig()->get('log')) && $message) {
            $ost->logDebug("RewritePlugin", $message);
        }
        if (self::DEBUG)
            error_log("osTicket RewritePlugin: $message");
    }

    /**
     * Required stub.
     *
     * {@inheritdoc}
     *
     * @see Plugin::uninstall()
     */
    function uninstall()
    {
        $errors = array();
        // Do we send an email to the admin telling him about the space used by the archive?
        global $ost;
        $ost->alertAdmin('Plugin: Rewriter has been uninstalled', "Forwarded messages will now appear from the forwarder, as with normal email.", true);
        
        parent::uninstall($errors);
    }

    /**
     * Plugins seem to want this.
     */
    public function getForm()
    {
        return array();
    }
}