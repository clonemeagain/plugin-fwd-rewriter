# osTicket Ticket Rewriter

Enables rewriting of forwarded messages before a new ticket is saved.
Ensures that the original sender of a message is preserved in the ticket metadata, and allows replies to go back to them.

- Admin options allow you to specify which domains are allowed to be rewritten (ie, enter your company domain). 
- Admin option to parse/rewrite messages from Drupal Contact forms
- Admin option to enable logging of actions into the osTicket admin logs

## To install
- Download master [zip](https://github.com/clonemeagain/plugin-fwd-rewriter/archive/master.zip) and extract into `/include/plugins/rewriter`
- Then Install and enable as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Rewriter` plugin