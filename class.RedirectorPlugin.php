<?php
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
class RedirectorPlugin extends Plugin {
	/**
	 * Which config class to load
	 *
	 * @var string
	 */
	var $config_class = 'RedirectorPluginConfig';
	
	/**
	 * Set to TRUE to enable webserver logging
	 * 
	 * @var boolean
	 */
	const DEBUG = FALSE;
	
	/**
	 * Hook the bootstrap process, wait for tickets to be created.
	 *
	 * Run on every instantiation, so needs to be concise.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::bootstrap()
	 */
	public function bootstrap() {
		
		// Listen for new tickets being created:
		Signal::connect ( 'ticket.create.before', function ($obj, &$vars) {
			$this->process_ticket ( $vars );
		} );
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
	private function process_ticket(&$vars) {
		// Retrieve the text from the ThreadEntryBody, as per v1.10
		$message_body = $vars ['message']->getClean ();
		
		// Could trim in regex.. but easier to trim here.
		$subject = trim ( $vars ['subject'] );
		
		// Will need the config
		$c = $this->getConfig ();
		
		// Build a fancy regex to match domain names
		// Will likely break horribly if they put anything but commas in there.
		$regex_of_allowed_domains = '/.+@(' . str_replace ( ',', '|', $c->get ( 'domains' ) ) . ')/i';
		
		// Need to find if the message was forwarded.
		// Use the admin-defined list of domains to build the regex to look for,
		// Then check the subject for "Fwd: ", "[Fwd:...]", "... (fwd)" as per SO link.
		// TODO: Combine $subject matches into one regex, the first is more likely though.
		if (preg_match ( $regex_of_allowed_domains, $vars ['email'] ) && (preg_match ( '/^\[?Fwd: /i', $subject ) || preg_match ( '/\(fwd\)$/i', $subject ))) {
			
			// We have a forwarded message (according to the subject)
			$this->log ( "Matched forwarded subject: $subject" );
			
			// Have to find the original sender
			// Attempting to find this from the body text:
			// // From: SomeName &lt;username@domain.name&gt;
			// The body will be html gibberish though.. have to decode it before checking
			$matchable_body = html_entity_decode ( $message_body );
			
			// This should match the text:
			// From: "Name" <"User@Email">
			// Which most forwarded messages seem to have.
			$sender = array ();
			if (preg_match_all ( '/From: (.+) <(.+?)>/i', $matchable_body, $sender ) != false) {
				return $this->rewrite ( $vars, $sender );
			} else {
				// Disaster, it is definitely a forwarded message, yet we can't find the details inside it.
				$this->log ( "Unable to rewrite $subject, No 'From: {name} <{email}>' found." );
			}
		} elseif ($c->get ( 'drupal' ) && preg_match ( '/sent a message using the contact form at/i', $message_body )) {
			
			// The message body indicates it could be sent from a Drupal /contact form
			// Luckily the default is PlainText (for Drupal 7 and lower at least)
			$this->log ( "Matched website message." );
			
			// Attempt to locate the sender in the first line.
			// Will fetch the name, then the email which is in (braces)
			$sender = array ();
			if (preg_match_all ( '#([\w ]+) \((.*)\) sent a message using the contact form at#i', $message_body, $sender )) {
				return $this->rewrite ( $vars, $sender );
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
	 *        	(output from preg_match_all with two capture groups name/email in that order)
	 */
	private function rewrite(array &$vars, array $matches) {
		// Check for valid data.
		if (! isset ( $matches [1] [0] ) || ! isset ( $matches [2] [0] )) {
			if (self::DEBUG)
				error_log ( "Unable to match with this invalid data, check the regex? check the domains? something borked." );
			
			return; // Bail.
		}
		// Get the last entry in each array as the proposed "Original Sender" of the first message in the chain.
		$original_name = array_pop ( $matches [1] );
		$original_email = array_pop ( $matches [2] );
		
		// The current details of the soon-to-be-ticket
		$current_sender_name = $vars ['name'];
		$current_sender_email = $vars ['email']; // We don't validate email because User::fromVars will do it
		                                         
		// Verify that the new email isn't the same as the current one, no point rewriting things if it's the same.
		if ($original_email == $current_sender_email) {
			if (self::DEBUG)
				error_log ( "The forwarded message is from the same person, they forwarded to themselves? bailing" );
			return;
		}
		
		// All Good? We're ready!
		// Rewrite the ticket variables with the new data
		// Uses osTicket v1.10 semantics
		$user = User::fromVars ( array (
				'name' => $original_name,
				'email' => $original_email 
		) );
		$vars ['uid'] = $user->getId ();
		$this->log ( "Rewrote ticket details: $current_sender_name -> {$original_name}" );
		
		// See if admin want's us to add a note about this in the message. (Ticket hasn't been created yet, so can't add an Admin Note to the thread)
		if ($this->getConfig ()->get ( 'note' )) {
			$original_text = $vars ['message']->getClean ();
			$admin_note = $this->getConfig ()->get ( 'note-text' ) . "\n\n";
			$vars ['message']->body = $admin_note . $original_text;
		}
	}
	
	/**
	 * Logging function,
	 * Ensures we have permission to log before doing so
	 *
	 * Logs to the Admin logs, and to the webserver logs.
	 *
	 * @param string $message        	
	 */
	private function log($message) {
		global $ost;
		static $can_log;
		if (! isset ( $can_log ))
			$can_log = $this->configGet ()->get ( 'log' );
		
		if ($can_log && $message) {
			$ost->logDebug ( "RedirectPlugin", $message );
			if (self::DEBUG)
				error_log ( "osTicket RedirectPlugin: $message" );
		}
	}
	
	/**
	 * Required stub.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::uninstall()
	 */
	function uninstall() {
		$errors = array ();
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugins seem to want this.
	 */
	public function getForm() {
		return array ();
	}
}