<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\DataContainer;
use Contao\Input;
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
 * The block follows the notification fields while the user picks, without saving – like the
 * conditional fields of this mask, which avoid Contao's submitOnChange because it saves the
 * whole record. The server renders the route of every notification the fields offer, one
 * tbody per field and notification ("choice", e.g. "invite:12"); workflow-mail-route.js shows
 * the ones that are selected and says so while the selection differs from the saved one.
 * Without the script the block shows the saved selection.
 *
 * A pseudo field (input_field_callback, no column of its own), resolved by class name via
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

        $saved = [];

        foreach (self::KINDS as $kind => $field) {
            $saved[$kind] = max(0, (int) $workflow->{$field});
        }

        $selected = array_map(static fn (string $kind, int $id): string => $kind.':'.$id, array_keys($saved), array_values($saved));

        // Every notification the fields offer, plus the saved ones (a deleted notification has
        // no route). Notifications without a published message have none either.
        $routesById = $this->mailRoute->forNotifications([...$this->mailRoute->notificationIds(), ...array_values($saved)]);

        $routesByChoice = [];
        $bodies = '';

        foreach (self::KINDS as $kind => $field) {
            foreach ($routesById as $id => $routes) {
                $choice = $kind.':'.$id;
                $routesByChoice[$choice] = $routes;
                $rows = '';

                foreach ($routes as $route) {
                    $rows .= $this->row($this->text($lang, 'kind_'.$kind), $route, $lang);
                }

                $bodies .= '<tbody data-wf-route-for="'.$choice.'"'.$this->style('', \in_array($choice, $selected, true)).'>'.$rows.'</tbody>';
            }
        }

        if ([] === $routesByChoice) {
            return '';
        }

        $warnings = MailRoute::warningsByChoice(
            $routesByChoice,
            $this->mailbox->address(),
            $this->health->isConfigured(),
            BounceHealth::STATE_CONFIG_ERROR === $this->health->read()['state'],
        );

        $warningHtml = '';

        foreach ($warnings as $warning) {
            $text = vsprintf($this->text($lang, 'warn_'.$warning['key']), array_map(StringUtil::specialchars(...), $warning['args']));
            $warningHtml .= '<p class="tl_error"'.$this->style('margin:0 0 .4em', [] !== array_intersect($warning['choices'], $selected))
                .' data-wf-route-for="'.implode(' ', $warning['choices']).'">'.$text.'</p>';
        }

        // The ids of the three selects: "ctrl_<field>", in "edit multiple" "ctrl_<field>_<id>".
        $suffix = 'editAll' === Input::get('act') ? '_'.(int) $dc->id : '';
        $config = ['fields' => [], 'saved' => []];

        foreach (self::KINDS as $kind => $field) {
            $config['fields'][$kind] = 'ctrl_'.$field.$suffix;
            $config['saved'][$kind] = (string) $saved[$kind];
        }

        $GLOBALS['TL_JAVASCRIPT']['wf_mail_route'] = 'bundles/contaoworkflow/workflow-mail-route.js|static';

        $anySelected = [] !== array_intersect(array_keys($routesByChoice), $selected);

        return '<div class="widget clr wf-mail-route" data-wf-route="'.htmlspecialchars(json_encode($config, JSON_THROW_ON_ERROR), ENT_QUOTES).'"'.$this->style('', $anySelected).'>'
            .'<h3><label>'.$this->text($lang, 'title').'</label></h3>'
            .'<p class="tl_info"'.$this->style('margin:.2em 0 .4em', false).' data-wf-route-unsaved>'.$this->text($lang, 'unsaved').'</p>'
            .'<table class="tl_listing" style="width:auto;margin:.2em 0 .6em;">'
            .'<thead><tr><th class="tl_folder_tlist"></th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_from').'</th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_answers').'</th>'
            .'<th class="tl_folder_tlist">'.$this->text($lang, 'col_bounces').'</th></tr></thead>'
            .$bodies
            .'</table>'
            .$warningHtml
            .'<p class="tl_help tl_tip" style="margin:0">'.$this->text($lang, 'hint').'</p></div>';
    }

    /**
     * The one style attribute of an element (a second one would be ignored by the browser),
     * hidden unless visible. Inline display, not the hidden attribute: Contao's back-end CSS
     * sets display on some of these elements, which would override [hidden].
     */
    private function style(string $base, bool $visible): string
    {
        $style = implode(';', array_filter([$base, $visible ? '' : 'display:none']));

        return '' === $style ? '' : ' style="'.$style.'"';
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
