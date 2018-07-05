<?php
require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.message.php';

class RewriterPluginConfig extends PluginConfig {

  // Provide compatibility function for versions of osTicket prior to
  // translation support (v1.9.4)
  function translate() {
    if (! method_exists('Plugin', 'translate')) {
      return array(
          function ($x) {
            return $x;
          },
          function ($x, $y, $n) {
            return $n != 1 ? $y : $x;
          }
      );
    }
    return Plugin::translate('rewriter');
  }

  function pre_save($config, &$errors) {
    // validate expressions, add warnings etc.
    if ($config['regex-rewrite']) {
      $rules = explode("\n", $config['regex-rewrite']);
      foreach ($rules as $rule) {
        if (! $rule)
          continue;
        list ($pattern, $replacement) = explode(':', $rule);
        if (false === @preg_match($pattern, null)) {
          $errors['err'] = 'Cannot compile regular expression:' . $pattern;
          return false;
        }
        Messages::success("Regex $pattern will be replaced with $replacement");
      }
    }
    if ($config['text-rewrite']) {
      $rules = explode("\n", $config['text-rewrite']);
      foreach ($rules as $rule) {
        if (! $rule)
          continue;
        list ($pattern, $replacement) = explode(':', $rule);
        Messages::success("String $pattern will be replaced with $replacement");
      }
    }
    if ($config['email-rewrite']) {
      $rules = explode("\n", $config['email-rewrite']);
      foreach ($rules as $rule) {
        if (! $rule)
          continue;
        list ($pattern, $replacement) = explode(':', $rule);
        Messages::success(
          "Email string $pattern will be replaced with $replacement");
      }
    }
    return TRUE;
  }

  /**
   * Build an Admin settings page.
   *
   * {@inheritdoc}
   *
   * @see PluginConfig::getOptions()
   */
  function getOptions() {
    list ($__, $_N) = self::translate();
    // TODO: figure out the domain-name from $cfg->get('helpdesk_url').. except it's not available yet (in bootstrap cycle I mean)
    return array(
        'ri' => new SectionBreakField(
          array(
              'label' => $__('Rewriter Configuration')
          )),
        'log' => new BooleanField(
          array(
              'default' => FALSE,
              'label' => $__('Show rewriting in logs'),
              'hint' => $__(
                "Enable to aid debugging, logs appear in Admin -> Dashboard -> System Logs")
          )),
        'domains' => new TextareaField(
          array(
              'label' => $__('Forward Rewritable Domains'),
              'placeholder' => $__(
                'Enter your trusted domain names, ie: company.com.tld'),
              'hint' => $__(
                "Separate with a comma if more than one required, not email addresses, full domains (the part after @), if empty, Nobody is allowed to forward."),
              'configuration' => array(
                  'html' => FALSE
              )
          )),
        'note' => new BooleanField(
          array(
              'default' => TRUE,
              'label' => $__('Show rewriting in text'),
              'hint' => $__('Adds the following note.')
          )),
        'note-text' => new TextareaField(
          array(
              'default' => $__(
                'Ticket modified upon receipt as it was forwarded to us.'),
              'label' => $__('Note Text'),
              'hint' => $__('This get\'s prepended to the message body')
          )),
        'dr' => new SectionBreakField(
          array(
              'label' => 'Explicitly defined parsers'
          )),
        'drupal' => new BooleanField(
          array(
              'default' => TRUE,
              'label' => $__('Rewrite Drupal Contact Form emails'),
              'hint' => $__(
                "Rewrite Drupal contact-form emails.")
          )),
        'zendesk' => new BooleanField(
          array(
            'default' => FALSE,
            'label' => $__('Rewrite Zendesk contact emails'),
            'hint' => $__('Rewrite Zendesk chat notification emails.')
          )),
        'dsf' => new SectionBreakField(
          array(
              'label' => $__('Attachment Deletion Options')
          )),
        'delete-attachments' => new BooleanField(
          array(
              'default' => FALSE,
              'label' => $__('Delete all attachments'),
              'hint' => $__('Removes all attachments from all incoming emails.')
          )),
        'delete-for-departments' => new TextboxField(
          array(
              'label' => $__('Purge email attachments for a department.'),
              'default' => FALSE,
              'hint' => $__(
                "Enter the name of the department or it's ID number to purge incoming email attachments for that department (multiple, seperate with commas).")
          )),

        'sba' => new SectionBreakField(
          array(
              'label' => $__(
                'Find & Replace: one per line, seperate split with : case insensitive'),
              'hint' => $__(
                'A missing second value indicates delete the match.')
          )),
        'email-rewrite' => new TextareaField(
          array(
              'label' => $__('Arbitrary Rewrite email Addresses'),
              'hint' => $__(
                'Use find:replace pairs, like: @internal.local:@company.com'),
              'configuration' => array(
                  'html' => FALSE
              )
          )),
        'text-rewrite' => new TextareaField(
          array(
              'label' => $__('Arbitrary Rewrite on Subject & Message'),
              'hint' => $__('Use find:replace pairs'),
              'configuration' => array(
                  'html' => FALSE
              )
          )),
        'sbr' => new SectionBreakField(
          array(
              'label' => $__('DANGER! DANGER!'),
              'hint' => 'http://php.net/manual/en/function.preg-replace.php Learn this!, the $pattern is the value on the left, the $replacement is the value on the right, the $subject is the entire $vars array.'
          )),
        'regex-rewrite' => new TextareaField(
          array(
              'configuration' => array(
                  'html' => FALSE
              ),
              'label' => $__('DANGERZONE: Regex Rewriter'),
              'hint' => $__('Use pattern:replacement one per line')
          ))
    );
  }
}