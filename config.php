<?php
require_once INCLUDE_DIR . 'class.plugin.php';
class RewriterPluginConfig extends PluginConfig {
	// Provide compatibility function for versions of osTicket prior to
	// translation support (v1.9.4)
	function translate() {
		if (! method_exists ( 'Plugin', 'translate' )) {
			return array (
					function ($x) {
						return $x;
					},
					function ($x, $y, $n) {
						return $n != 1 ? $y : $x;
					} 
			);
		}
		return Plugin::translate ( 'rewriter' );
	}
	
	/**
	 * Build an Admin settings page.
	 *
	 * {@inheritdoc}
	 *
	 * @see PluginConfig::getOptions()
	 */
	function getOptions() {
		list ( $__, $_N ) = self::translate ();
		// TODO: figure out the domain-name from $cfg->get('helpdesk_url').. except it's not available yet (in bootstrap cycle I mean)
		return array (
				'ri' => new SectionBreakField ( array (
						'label' => $__ ( 'Rewriter Configuration' ) 
				) ),
				'log' => new BooleanField ( array (
						'default' => FALSE,
						'label' => $__ ( 'Show rewriting in logs' ),
						'hint' => $__ ( "Enable to aid debugging, logs appear in Admin -> Dashboard -> System Logs" ) 
				) ),
				'note' => new BooleanField ( array (
						'default' => TRUE,
						'label' => $__ ( 'Show rewriting in text' ),
						'hint' => $__ ( 'Adds the following note.' ) 
				) ),
				'note-text' => new TextareaField ( array (
						'default' => $__ ( 'Ticket modified upon receipt as it was forwarded to us.' ),
						'label' => $__ ( 'Note Text' ),
						'hint' => $__ ( 'This get\'s prepended to the message body' ) 
				) ),
				'di' => new SectionBreakField ( array (
						'label' => 'Permission' 
				) ),
				'domains' => new TextareaField ( array (
						'label' => $__ ( 'Rewritable Domains' ),
						'placeholder' => $__ ( 'Enter your trusted domain names, ie: company.com.tld' ),
						'hint' => $__ ( "Separate with a comma if more than one required, not email addresses, full domains (the part after @)." ) 
				) ),
				'dr' => new SectionBreakField ( array (
						'label' => 'Drupal Contact Parser' 
				) ),
				'drupal' => new BooleanField ( array (
						'default' => TRUE,
						'label' => $__ ( 'Rewrite Drupal Contact Form emails' ),
						'hint' => $__ ( "Drupal sends specific email formats that we can look for." ) 
				) ) 
		);
	}
}