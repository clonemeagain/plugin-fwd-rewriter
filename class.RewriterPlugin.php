<?php
require_once (INCLUDE_DIR . 'class.format.php');
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to modify tickets as they are being created.
 * Rewrite or Redirect them from the forwarding user to the actual Owner For
 * instance, using a webform or other to create your tickets via email, the
 * "owner" of a ticket will be whomever email user you sent the email from, not
 * the email address that was entered by the sender. This plugin allows you to
 * rewrite the ticket details transparently to osTicket.
 */
class RewriterPlugin extends Plugin {

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
   * Turn on to fill your cron logs with every piece of data we have to play
   * with. Great for development/debugging.. not so great in a busy ticket
   * system.
   *
   * @var boolean
   */
  const DUMPWHOLETHING = FALSE;

  /**
   * Hook the bootstrap process, wait for tickets to be created. Run on every
   * instantiation, so needs to be concise.
   *
   * {@inheritdoc}
   *
   * @see Plugin::bootstrap()
   */
  public function bootstrap() {
    if (self::DUMPWHOLETHING) {
      $this->log("Bootstrappin..");
    }
    // Listen for new tickets being created:
    Signal::connect('ticket.create.before',
      function ($obj, &$vars) {
        if (self::DUMPWHOLETHING) {
          $this->log("Received signal ticket.create.before");
          if (function_exists('xdebug_var_dump')) { // shouldn't be enabled on prod, but if it is, and DUMPWHOLETHING is enabled, then woo:
            xdebug_break();
          }
          else {
            print_r($vars);
          }
        }
        // Only email would send a mail ID.. right? (API can simply set the sender's email manually, the web isn't forwarding.. )
        if (isset($vars['mid'])) {
          $this->process_ticket($vars);
        }
        elseif (self::DEBUG) {
          $this->log("Ignoring invalid ticket source.");
        }
      });

    // See if admin wants to remove all attachments:
    if ($this->getConfig()->get('delete-attachments')) {
      // Listen to the mail.processed signal, and simply drop any attachments..
      // if you wanted to delete all attachments, but didn't want to turn them off
      // in the admin config for some reason..
      // Note: Haven't found any signals for piped email..
      // Really wish there was one before the attachments were downloaded.
      // apparently not.
      Signal::connect('mail.processed',
        function ($mf, &$vars) {
          $this->log("All Attachments Purged, as instructed.");
          $vars['attachments'] = array();
        });
    }
    elseif ($this->getConfig()->get('delete-for-departments')) {
      // Little bit tricker, we need to wait for the thread to be created for the ticket,
      // check it's department, if known, match it with the admin specified departments
      // then purge any attachments found.
      Signal::connect('threadentry.created',
        function ($entry) {
          $this->log(
            "Received threadentry.created signal, told to check for departments.");
          $this->purgeAttachmentsByDepartment($entry);
        });
    }
 }

  /**
   * Takes an array of variables and checks to see if it matches the normal
   * "Forwarded Message" attributes.. Need to find the earliest sender combo in
   * the array. There could be quite a chain Customer email's Dealer Dealer
   * forwards to Distributor Distributor forwards to Manufacturer Manufacturer
   * internally forwards to Ticketing system ETC.. We actually want the Customer
   * one. the First one. Which, in this case, is the LAST one. There is
   * apparently no RFC for forwarded messages (as per:
   * http://stackoverflow.com/a/4743303)
   *
   * @param array $vars
   */
  private function process_ticket(&$vars) {
    // This only works for email.. Tickets created by admins with manually entered "Fwd" stuff,
    // get's filtered by redactor or whatever that is called, so it doesn't work there.
    if (! $vars['message'] instanceof ThreadEntryBody) {
      if (self::DEBUG)
        $this->log(
          "Unable to process ticket, message body wasn't in expected format, this plugin only works for 1.10+. ");
      return;
    }

    $restrict_forwarding_to_these_domains = $this->getConfig()->get('domains');
    // if no domains are specified, we just allow NO domains to forward..
    if (strlen($restrict_forwarding_to_these_domains)) {
      $this->rewriteForward($vars, $restrict_forwarding_to_these_domains);
    }

    // Test for Drupal messages:
    if ($this->getConfig()->get('drupal') &&
       preg_match('/sent a message using the contact form at/i', $message_body)) {

      // The message body indicates it could be sent from a Drupal /contact form
      // Luckily the default is PlainText (for Drupal 7 and lower at least)
      if (self::DEBUG)
        $this->log("Matched Drupal message.");

        // Attempt to locate the sender in the first line.
        // Will fetch the name, then the email which is in (braces)
      $sender = array();
      // TODO: Figure out what this would be for different languages?
      if (preg_match_all(
        '#([\w ]+) \((.*)\) sent a message using the contact form at#i',
        $message_body, $sender)) {
        $this->rewrite($vars, $sender);
      }
    }
    // See if Zendesk Chat emails need to be rewritten:
    if($this->getConfig()->get('zendesk') && $vars['email'] == 'noreply@zopim.com'){
      $this->rewriteZendesk($vars);
    }

    // See if admin has added any email rewriting rules:
    if ($rules = $this->getConfig()->get('email-rewrite')) {
      $this->rewriteEmail($vars, $rules);
    }
    // See if admin has added any text rewriting rules:
    if ($rules = $this->getConfig()->get('text-rewrite')) {
      $this->rewriteText($vars, $rules);
    }
    // See if admin has added any regex rewriting rules:
    if ($rules = $this->getConfig()->get('regex-rewrite')) {
      $this->rewriteTextRegex($vars, $rules);
    }
  }

  private function rewriteForward($vars, $restrict_forwarding_to_these_domains) {
    // Build a fancy regex to match domain names, restricts the people who can forward
    // Will likely break horribly if they put anything but commas in there.
    // TODO: Make admin option simply "the regex"..? could be good.
    // Alternately, it would mean every osTicket admin would have to learn Regular Expressions..
    $regex_of_allowed_domains = '/.+@(' .
       str_replace(',', '|', $restrict_forwarding_to_these_domains) . ')/i';
    if (! preg_match($regex_of_allowed_domains, $vars['email'])) {
      if (self::DEBUG) {
        $this->log("Sender wasn't in list of allowed domains.");
      }
      else {

        // Retrieve the text from the ThreadEntryBody, as per v1.10
        $message_body = $vars['message']->getClean();

        // Could trim in regex.. but easier to trim here.
        // Only works if the Issue Summary field is a "Short Answer" textbox.. not a choice. :-|
        $subject = trim($vars['subject']);
        if (! $subject) {
          $this->log("Message had no subject, ignoring.");
          return;
        }

        // Need to find if the message was forwarded.
        // Check the subject for "Fwd: ", "[Fwd:...]", "... (fwd)" as per http://stackoverflow.com/a/4743303
        // TODO: Combine $subject matches into one regex, the first is more likely though.
        // note the second ?, because Office365 forwards with "Fw: subject".. special.
        if (preg_match('/^\[?Fwd?: /i', $subject) ||
           preg_match('/\(fwd\)$/i', $subject)) {

          // We have a forwarded message (according to the subject)
          $this->log("Matched forwarded subject: $subject");

          // Have to find the original sender
          // Attempting to find this from the body text:
          // // From: SomeName &lt;username@domain.name&gt;
          // The body will be html gibberish though.. have to decode it before checking
          // if (self::DUMPWHOLETHING)
          // print "Message as parser sees it: \n$message_body\n";


          // We'll start with the regex check, if it works, we get the name & email in one go:
          if ($sender = $this->optimisticSearch($message_body)) {
            $this->rewrite($vars, $sender);
          }
          else {
            // ok, "simple" mode didn't work.. darn.. (expletives have been deleted)
            // by passing the full $vars['message'] item, we can check the text-version as well.
            $sender = $this->deepSearchForSender($vars['message']);
            if ($sender) {
              $this->rewrite($vars, $sender);
            }
            else {
              // Disaster, it is a forwarded message, yet we can't find the details inside it.
              $this->log(
                "Unable to rewrite $subject, No 'From: {name} <{email}>' found.");
            }
          }
        }
      }
    }
  }

  /**
   * This should match the text: From: "Name" <"User@Email"> Which most
   * forwarded messages seem to have, possibly because of
   * https://tools.ietf.org/html/rfc821#page-7 note, names can have almost
   * anything.. "James O'Brian" etc.. strip_tags should leave it with a line
   * like: "From: Name sender@domain.com&gt;" we need to match the word "From:",
   * anything (with spaces around it) followed by something with an @ symbol
   * between it and the string "&gt" Those two things are what we need to
   * rewrite with. Except fucking gmail.. of course: From: <b
   * class="gmail_sendername">Name</b> <span
   * dir="ltr">&lt;name@domain.com&gt;</span> From: <b>Sender Name</b> <span
   * dir="ltr">name@domain.com&gt;</span> wtf is that regex? Where is my will to
   * live? Whyyyy also, how the fuck did I write that? check out regexr.com and
   * play till you get it working.. many complicated! why don't we just match
   * the email address, fuck the name!
   *
   * @param string $message_body
   * @return array|boolean
   */
  private function optimisticSearch($message_body) {
    $sender = array();
    if (preg_match_all(
      '/From:(.*)(?: |<|;|>)([\w\d_\-\.]+@[\w\d_\-\.]+)(?:&|>| )/i',
      $matchable_body, $sender)) {
      if (self::DUMPWHOLETHING) {
        print "Found sender using optimisticSearch: " . print_r($sender, true);
      }
      return $sender;
    }
    return FALSE;
  }

  /**
   * Uses advanced technique known as Brute Force to locate the original sender.
   * We convert the HTML of the message into a DOMDocument, then iterate through
   * all the nodes that make it up. In each of the nodes, we search the text of
   * the node looking for email addresses, because, From: Name
   * <email@address.org> will appear at some point as a piece of text. When
   * we've found the email address, we look through the lines that make up that
   * node's text and find the From: part, looking backwards. When we find the
   * From: line, we trim that off, and trim off the email address, and we're
   * just left with the name.
   *
   * @param unknown $html
   * @return boolean|string[][]
   */
  private function deepSearchForSender($html) {
    $name = $email = '';

    // because the $vars['message'] is actually different to $vars['message']->getClean()
    // we should try the optmitistic search again.
    if ($sender = $this->optimisticSearch($html)) {
      return $sender;
    }
    // no dice?
    // ok
    // Let's try loading the message into a DOMDocument, and finding the From text, then an adjacent email address.. right?
    // I mean, how hard can it be. .. famous last words.
    libxml_use_internal_errors(TRUE); // ignore libxml parser errors
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED); // don't automatically add <html> or <body> tags if absent.
    foreach ($dom->getElementsByTagName('*') as $node) { // iterate over the document's DOMNodeList
      if (self::DUMPWHOLETHING)
        print "DS: Checking node with text: " . $node->nodeValue . "\n";

        // Find any email addresses.. From: Name <email@address.org> should SHOULD be fairly near the top
      $possibles = $this->findEmailAddresses($node->nodeValue);

      // Depending on how many email addresses we found in that block, it could be the bit we're after!
      if (count($possibles) > 0) {

        // let's go for broke, ANY addresses in that block is likely to be us.
        $lines = explode(PHP_EOL, $node->nodeValue);

        // Go over each email address, check each line of text that we found the addresses in:
        foreach ($possibles as $email) {
          foreach ($lines as $line) {
            if (self::DUMPWHOLETHING)
              print "DS: Looking for $email in line:  $line\n";
              // Let's find the "From: " bit in that line that has the email address, then call the line a winner.
            if (stripos($line, 'From:') !== FALSE &&
               stripos($line, $email) !== FALSE) {
              if (self::DUMPWHOLETHING) {
                print "DS: Found match for /From:.*$email/ \n"; // pretty sure two stripos's is faster than preg_match.. dunno
              }
              // Success, this line has the text we want.
              // Need the text after From as the sender, and we already know the address.
              $name = str_replace(
                array(
                    '<',
                    '>'
                ), '', strip_tags($line)); // is strip_tags what we want?
              $name = str_replace('From:', '', $name); // remove From: from the line
              $name = str_replace($email, '', $name); // remove the email address from the line


              if (self::DUMPWHOLETHING)
                print "Found our guy? name: $name with email: $email\n";

                // Skip back up the three foreach's
              break 3;
            }
            elseif (self::DUMPWHOLETHING) {
              print "DS: DID NOT MATCH LINE: $line\n";
            }
          }
        }
      }
    }

    if (! $email) {
      // we can always pull the first half of the email as the name, but without the email, nothing doin.
      return FALSE;
    }

    // recreate the structure of the output of preg_match_all
    // $matches[0][..] == 'Pattern matches, not the capture groups.. so, skip this'
    // $matches[1][..] == 'Names'
    // $matches[2][..] == 'Email addresses'
    $sender = array(
        array(),
        array(
            trim($name)
        ),
        array(
            trim($email)
        )
    );
    if (self::DUMPWHOLETHING)
      print "Found sender in deepSearch: " . print_r($sender, true);

    return $sender;
  }

 private function rewriteZendesk(&$vars){
    $text = $vars['message']->getClean();
    // Find the original sender
    $emails = $this->findEmailAddresses($text);
    // how many can there be?
    // Let's assume the last one..
    $senders_email = array_pop($emails);
    // Find the original name:
    // Delete any new lines and strip html:
    $lineless = str_replace("\n",'', strip_tags($text));
    // Name exists between NAME & EMAIL, like NAMEJames SmithEMAIL etc, so
    // we just replace the whole piece of text with whatever is between those:
    $senders_name = preg_replace("/.*NAME([a-z' ]+)EMAIL.*/i","\$1",$lineless);
    if(!$senders_name){
      $senders_name = $senders_email; // make do..
    }

    // Now we can rewrite the sender
      $sender = array(
          array(),
          array(
              trim($senders_name)
          ),
          array(
              trim($senders_email)
          )
      );
    $this->rewrite($vars,$sender);
 }

  /**
   * Finds any email addresses in a piece of text, Returns an array of those
   * addresses.
   *
   * @see https://stackoverflow.com/a/3901303
   * @see https://stackoverflow.com/a/8131211
   *
   *
   * @param string $text
   * @return mixed
   */
  private function findEmailAddresses($text) {
    $matches = array();
    $matches[0] = array();

    // this regex handles more email address formats like a+b@google.com.sg
    // calm your tits email: https://en.wikipedia.org/wiki/Email_address#Examples
    // wow.
    $pattern = "/(?:[A-Za-z0-9!#$%&'*+=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&'*+=?^_`{|}~-]+)*|\"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")@(?:(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[A-Za-z0-9-]*[A-Za-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])/";

    // fill $matches with email addresses found in $text
    preg_match_all($pattern, $text, $matches);

    return $matches[0];
  }

  /**
   * A little more powerful.. It's assumed you know what you're doing when you
   * write a regex. Let's get creative.
   *
   * @param array $vars
   * @param array $rules
   */
  private function rewriteTextRegex($vars, $rules) {
    $needles = $replacements = array();

    foreach (explode(PHP_EOL, $rules) as $rule) {
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
      $this->log(
        "Going to get brutal with regex against the ticket.. hold onto your hat!");
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
      }
      elseif (is_string($val)) {
        // we can work with text:
        $new_val = preg_replace($needles, $replacements, $val);
        $vars[$key] = $new_val ?: $val;
      }
      // TODO: Decide how deep the rabbit hole we want to go..
      // attachments/recipients/etc are in a sub array
    }
  }

  /**
   * Rewrite Text things, like, the subject/body Simple find & replace
   * implementation.
   *
   * @param array $vars
   *          (the values osTicket constructed)
   * @param string $rules
   *          (the find:replace pairs admin entered, \n seperating each rule)
   */
  private function rewriteText(&$vars, &$rules) {
    foreach (explode(PHP_EOL, $rules) as $rule) {
      list ($find, $replace) = explode(':', $rule); // PHP 5/7 inverts the order of applying this.. FFS, why?


      if (! $find)
        continue;

      if (self::DEBUG)
        $this->log(
          "Using $find => $replace text rule on ticket with subject $subject\n");

      if ($vars['message'] instanceof ThreadEntryBody &&
         stripos($vars['message']->body, $find) !== FALSE) {
        $vars['message']->body = str_ireplace($find, $replace,
          (string) $vars['message']->body);
      }
      if (stripos($vars['subject'], $find) !== FALSE) {
        $vars['subject'] = str_ireplace($find, $replace, $vars['subject']);
      }
    }
  }

  private function rewriteEmail(&$vars, &$rules) {
    foreach (explode(PHP_EOL, $rules) as $rule) {
      list ($find, $replace) = explode(':', $rule); // PHP 5/7 inverts the order of applying this.. FFS, why?


      if (! $find)
        continue;

      if (self::DEBUG)
        $this->log(
          "Using $find => $replace email rule on ticket with subject $subject\n");

      {
        if (stripos($vars['email'], $find) !== FALSE) {
          $vars['email'] = str_ireplace($find, $replace, $vars['email']);
        }
        break;
      }
    }
  }

  /**
   * Rewrites the variables for the new ticket Owner The structure we are
   * expecting back from either preg_match_all will be a 2-level array $matches
   * $matches[1][..] == 'Names' $matches[2][..] == 'Email addresses' Now, to
   * find the LAST one.. we look at the end of the array
   *
   * @param array $vars
   * @param array $matches
   *          (output from preg_match_all with two capture groups name/email in
   *          that order)
   */
  private function rewrite(array &$vars, array $matches) {
    // Check for valid data.
    if (! isset($matches[1][0]) || ! isset($matches[2][0])) {
      if (self::DEBUG)
        error_log(
          "Unable to match with invalid data, check the regex? check the domains? something borked.");

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
      print
        "Going into rewrite with these details:\nOriginal: $current_sender_name <$current_sender_email>\nNew: $original_name <$original_email>\n";
    }

    // Verify that the new email isn't the same as the current one, no point rewriting things if it's the same.
    if ($original_email == $current_sender_email) {
      if (self::DEBUG)
        error_log(
          "The forwarded message is from the same person, they forwarded to themselves? bailing");
      return;
    }

    // All Good? We're ready!
    // Rewrite the ticket variables with the new data
    // Uses osTicket v1.10 semantics
    $vars['name'] = $original_name;
    $vars['email'] = $original_email;

    $user = User::fromVars(
      array(
          'name' => $original_name,
          'email' => $original_email
      ));
    if (self::DUMPWHOLETHING) {
      $this->log(
        "Attempted to make/find user for $original_name and $original_email");
      print_r($user); // DEBUG
    }
    if (! $user instanceof User) {
      // Was denied/spammer?
      // We can't use the $user object if it's not a User!
      // Also fails if Registration (user/pass) is required for users and the new one isn't actually a user.
      $this->log(
        "Unable to rewrite to this User $original_email, as we are unable to make an account for them.");
      // put it back!
      $vars['name'] = $current_sender_name;
      $vars['email'] = $current_sender_email;
      return;
    }
    $vars['uid'] = $user->getId();
    $msg = "Rewrote ticket details: $current_sender_name -> {$original_name}";
    $this->log($msg);
    if (self::DEBUG) {
      print $msg;
    }
    $this->addAdminNote($vars);
  }

  private function addAdminNote($vars) {
    // See if admin want's us to add a note about this in the message. (Ticket hasn't been created yet, so can't add an Admin Note to the thread)
    if ($this->getConfig()->get('note')) {
      $admin_note = $this->getConfig()->get('note-text') . "\n\n";
      $vars['message']->prepend($admin_note); // Another reason to test for ThreadEntryBody, we can use it's methods!
    }
  }

  /**
   * Tiny function that purges attachments for specified departments only
   *
   * @param ThreadEntry $entry
   */
  private function purgeAttachmentsByDepartment(ThreadEntry &$entry) {
    $departments = $this->getConfig()->get('delete-for-departments');
    $matchable_departments = (strpos($departments, ',') !== FALSE) ?
    // many entered: fill an array with each name
    explode(',', $departments) :
    // one entered: make it an array anyway:
    array(
        $departments
    );

    $this->log(
      "Purging attachments for departments: " .
         print_r($matchable_departments, true));

    // Get the ticket, to get the department
    $ticket = $this->getTicket($entry);
    if (! $ticket instanceof Ticket) {
      $this->log("Unable to find/get ticket for thread");
      return;
    }
    $ticket_department = $ticket->getDept();
    if (! $ticket_department instanceof Dept) {
      $this->log("Unable to find/get department for thread");
      return;
    }
    $thread = $entry->getThread();
    if (! $thread instanceof Thread) {
      return;
    }
    // We have enough pieces, let's check the departments the admin specified:
    foreach ($matchable_departments as $d) {
      $this->log("Looking up department: $d");
      if (is_string($d)) {
        $dept_id = Dept::getIdByName($d);
        $dept = Dept::lookup($dept_id);
      }
      else {
        $dept = Dept::lookup($d);
      }
      if (! $dept instanceof Dept) {
        $this->log("ERROR: unable to instantiate $d as department.");
      }
      if (self::DUMPWHOLETHING)
        $this->log(
          "Checking " . $dept->getName() . ' against . ' .
             $ticket_department->getName());
      if ($dept instanceof Dept && $dept->getId() == $ticket_department->getId()) {
        $this->log("Going to delete the attachments from the thread..");

        // Match, let's purge the attachments
        foreach ($entry->getAttachments() as $att) {
          if (! $att instanceof Attachment) {
            $this->log("Error, unable to delete attachment");
            continue;
          }
          $this->log("Deleting file attachment: " . $att->getFileName());
          Attachment::objects()->filter(
            array(
                'file_id' => $att->getFileId()
            ))
            ->delete();
        }

        // Return early, in case the first matches, no need to instantiate the next Department.
        return;
      }
    }
  }

  /**
   * Fetches a ticket from a ThreadEntry Copied from mentioner plugin! :-)
   *
   * @param ThreadEntry $entry
   * @return Ticket
   */
  private static function getTicket(ThreadEntry $entry) {
    static $ticket;
    if (! $ticket) {
      // aquire ticket from $entry.. I suspect there is a more efficient way.
      $ticket_id = Thread::objects()->filter(
        [
            'id' => $entry->getThreadId()
        ])
        ->values_flat('object_id')
        ->first()[0];

      // Force lookup rather than use cached data..
      $ticket = Ticket::lookup(
        array(
            'ticket_id' => $ticket_id
        ));
    }
    return $ticket;
  }

  /**
   * Logging function, Ensures we have permission to log before doing so
   * Attempts to log to the Admin logs, and to the webserver logs if debugging
   * is enabled.
   *
   * @param string $message
   */
  private function log($message) {
    global $ost;

    // hmm.. might not be available if bootstrapping isn't finished.
    if ($ost instanceof osTicket &&
       (self::DEBUG || $this->getConfig()->get('log')) && $message) {
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
  function uninstall() {
    $errors = array();
    // Do we send an email to the admin telling him about the space used by the archive?
    global $ost;
    $ost->alertAdmin('Plugin: Rewriter has been uninstalled',
      "Forwarded messages will now appear from the forwarder, as with normal email.",
      true);

    parent::uninstall($errors);
  }

  /**
   * Plugins seem to want this.
   */
  public function getForm() {
    return array();
  }
}