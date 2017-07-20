# osTicket - Ticket Rewriter Plugin

Enables rewriting of forwarded messages before a new ticket is created.
Ensures that the original sender of a message is preserved in the ticket metadata, and allows replies to go back to them.

- Admin options allow you to specify which domains are allowed to be rewritten (ie, enter your company domain). 
- Admin option to parse/rewrite messages from Drupal Contact forms
- Admin option to enable logging of actions into the osTicket admin logs

## To install
- Download master [zip](https://github.com/clonemeagain/plugin-fwd-rewriter/archive/master.zip) and extract into `/include/plugins/rewriter`
- Then Install and enable as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the `Ticket Rewriter` plugin
I suggest at least: "domain name", otherwise the forwarder will ignore all forwarded mail.

To start, you should probably enable logging. You can disable when you're done testing.

If you use Drupal on any external websites, and don't use an API to talk to osTickets (ie, the Contact forms email your ticket system), you can use the Drupal option to rewrite those inbound emails back into the original senders. 

## Caveats:

- Only works on Emails, possibly API (haven't tested), doesn't work for Web created tickets!
- Assumes English.. Sorry, I don't speak enough of even one foreign language to write regex for them. Let me know if you've any ideas!
- If you require user login, it won't rewrite a ticket to a non-existent user. Only tested with Registration disabled!
- Assumes osTicket v1.10+ The API changes a bit between versions..  
