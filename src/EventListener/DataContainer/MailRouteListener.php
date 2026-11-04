<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\StringUtil;
use Contao\System;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\Bounce\BounceHealth;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;
use Psimandl\WorkflowBundle\Service\Bounce\MailRoute;

/**
 * Shows, right under the notification fields of the edit mask, where the mails of this
 * workflow go: sender, replies, bounces – per notification – and what is wrong with it (a
 * sender or Reply-To that is the bounce mailbox, no mailbox at all, a gateway that does not
 * send mail).
 *
 * Computed from the saved values; after changing a notification, save to update it. A pseudo
 * field (input_field_callback, no column of its own), resolved by class name via
 * System::importStatic() – the service must therefore be public.
 */
class MailRouteListener
{
    private const KINDS = ['invite' => 'ncInvite', 'reminder' => 'ncReminder', 'result' => 'ncResult'];

    public function __construct(
        private readonly MailRoute $mailRoute,
        private readonly BounceMailbox $mailbox,
        private readonly BounceHealth $health,
    ) {
    }

    public function render(DataContainer $dc): string
    {
        $workflow = $dc->id ? WorkflowModel::findByPk((int) $dc->id) : null;

        if (null === $workflow) {
            return '';
        }

        System::loadLanguageFile('workflow_messages');
        $lang = $GLOBALS['TL_LANG']['workflow_route'] ?? [];

        $rows = '';
        $routes = [];

        foreach (self::KINDS as $kind => $field) {
            foreach ($this->mailRoute->forNotification((int) $workflow->{$field}) as $route) {
                $routes[] = $route;
                $rows .= $this->row($this->text($lang, 'kind_'.$kind), $route, $lang);
            }
        }

        if ([] === $routes) {
            return '';
        }

        $html = '<div class="widget clr wf-mail-route">'
            .'<h3><label>'.$this->text($lang, 'title').'</label></h3>'
            .'<table class="tl_listing" style="width:auto;margin:.2em 0 .6em;">'
            .'<tr><th class="tl_folder_tlist"></th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_from').'</th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_answers').'</th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_bounces').'</th></tr>'
            .$rows
            .'</table>';

        $warnings = MailRoute::warnings(
            $routes,
            $this->mailbox->address(),
            $this->health->isConfigured(),
            BounceHealth::STATE_CONFIG_ERROR === $this->health->read()['state'],
        );

        foreach ($warnings as $warning) {
            $text = vsprintf($this->text($lang, 'warn_'.$warning['key']), array_map(StringUtil::specialchars(...), $warning['args']));
            $html .= '<p class="tl_error" style="margin:0 0 .4em">'.$text.'</p>';
        }

        return $html.'<p class="tl_help tl_tip" style="margin:0">'.$this->text($lang, 'hint').'</p></div>';
    }

    /**
     * @param array{message: string, gateway: string, mail: bool, transport: string, from: string, fromSource: string, replyTo: string, answersTo: string, bounceTo: string, bounceToMailbox: bool} $route
     * @param array<string, mixed>                                                                                                                                                          $lang
     */
    private function row(string $label, array $route, array $lang): string
    {
        $cell = static fn (string $html): string => '<td class="tl_file_list" style="padding:.3em .8em .3em 0;vertical-align:top;">'.$html.'</td>';

        if (!$route['mail']) {
            return '<tr>'.$cell('<strong>'.$label.'</strong>')
                .'<td class="tl_file_list" colspan="3">'.sprintf($this->text($lang, 'not_mail'), StringUtil::specialchars($route['gateway'])).'</td></tr>';
        }

        $from = match ($route['fromSource']) {
            MailRoute::FROM_TRANSPORT => $this->address($route['from']).' <span class="tl_gray">'.sprintf($this->text($lang, 'from_transport'), StringUtil::specialchars($route['transport'])).'</span>',
            MailRoute::FROM_NOTIFICATION => $this->address($route['from']),
            default => '<span class="tl_gray">'.$this->text($lang, 'from_admin').'</span>',
        };

        $answers = '' !== $route['replyTo']
            ? $this->address($route['answersTo']).' <span class="tl_gray">'.$this->text($lang, 'answers_replyto').'</span>'
            : ('' !== $route['answersTo'] ? $this->address($route['answersTo']) : '<span class="tl_gray">'.$this->text($lang, 'from_admin').'</span>').' <span class="tl_gray">'.$this->text($lang, 'answers_from').'</span>';

        $bounces = $route['bounceToMailbox']
            ? $this->address($route['bounceTo']).' <span class="tl_gray">'.$this->text($lang, 'bounces_mailbox').'</span>'
            : ('' !== $route['bounceTo'] ? $this->address($route['bounceTo']) : '<span class="tl_gray">'.$this->text($lang, 'from_admin').'</span>').' <span class="tl_gray">'.$this->text($lang, 'bounces_from').'</span>';

        return '<tr>'.$cell('<strong>'.$label.'</strong>').$cell($from).$cell($answers).$cell($bounces).'</tr>';
    }

    private function address(string $address): string
    {
        return '<span style="white-space:nowrap;">'.StringUtil::specialchars($address).'</span>';
    }

    /**
     * @param array<string, mixed> $lang
     */
    private function text(array $lang, string $key): string
    {
        $value = $lang[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $key;
    }
}
