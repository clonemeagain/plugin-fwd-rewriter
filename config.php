<?php
require_once INCLUDE_DIR . 'class.plugin.php';

class RedirectorPluginConfig extends PluginConfig
{
    // Provide compatibility function for versions of osTicket prior to
    // translation support (v1.9.4)
    function translate()
    {
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
        return Plugin::translate('redirector');
    }

    /**
     * Build an Admin settings page.
     *
     * {@inheritDoc}
     *
     * @see PluginConfig::getOptions()
     */
    function getOptions()
    {
        list ($__, $_N) = self::translate();
        return array(

            'enabled' => new BooleanField(array(
                'default' => TRUE,
                'label' => $__('Allow Rewriting'),
                'hint' => $__("Allows messages to be rewritten to be from the original sender instead of the Forwarder.")
            )),
            'log' => new BooleanField(array(
                'label' => $__('Show rewriting in logs'),
                'hint' => $__("Enable to aid debugging, logs appear in Admin -> Dashboard -> System Logs (Log Level Debug), which you might have to enable in Admin -> Settings -> System -> Default Log Level.")
            )),
            'note' => new BooleanField(array(
                'default' => TRUE,
                'label' => $__('Show rewriting in ticket details.'),
                'hint' => 'Can be useful to help trace message back through original.'
            )),
            'domains' => new TextareaField(array(
                'label' => $__('Domains to enable rewriting'),
                'hint' => $__("Separate with a comma.")
            )),
            'drupal' => new BooleanField(array(
                'label' => $__('Rewrite Drupal Contact Form emails'),
                'hint' => $__("Drupal sends specific email formats that we can look for.")
            ))
        );
    }
}