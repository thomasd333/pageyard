<?php
/**
 * Contact form: messages go by e-mail (set it up in Global Configuration > E-mail) and are not stored.
 * @param to       recipient (default: the contact address from the configuration)
 * @param subject  subject of the e-mails (default: site name and sender)
 * @param consent  text of a checkbox that must be ticked, e.g. about personal data
 * @example consent="I agree to my data being used to answer this message."
 */

defined('PAGEYARD') or exit;

/** @var array<string, string|true> $params */
echo Pageyard\Site\ContactForm::render($params);
