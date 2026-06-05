<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Ensures a working, general-purpose front end form page so invitations can be sent (the
 * invitation link needs a page hosting the "workflow_form" module). Bootstrapped by the
 * demo, but **shared by every workflow**: the module resolves the entry and its workflow
 * purely from the token in the URL, so a single page at "/workflow-formular/<token>" serves
 * all workflows. New workflows simply point their "form page" at it.
 *
 * Built deliberately non-intrusively:
 *  - the page is **hidden from the navigation menu** (hide = 1),
 *  - it **adopts an existing site layout** (the most-used one) instead of a separate, bare
 *    one – so it looks like the rest of the site,
 *  - the module is placed via an **article + a "module" content element** (the standard
 *    way for a page that inherits the site layout),
 *  - only a dedicated theme holds the module; no existing page, layout, theme or file
 *    is modified.
 *
 * Strictly idempotent: records are looked up by marker name/alias and reused; an existing
 * page is healed (hidden + re-pointed at a site layout). Returns the page id, or 0 when
 * there is neither a published root nor a usable site layout to adopt.
 */
class DemoFormPage
{
    private const THEME_NAME = 'Workflow';
    private const MODULE_NAME = 'Workflow-Formular';
    private const PAGE_TITLE = 'Workflow-Formular';
    private const PAGE_ALIAS = 'workflow-formular';
    private const OLD_LAYOUT_NAME = 'Workflow Demo';
    // The form page is reached only via individual token links and must never be indexed.
    private const ROBOTS = 'noindex,nofollow';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function ensure(): int
    {
        $themeId = $this->ensureTheme();
        $moduleId = $this->ensureModule($themeId);
        $layoutId = $this->findSiteLayout();

        $pageId = (int) ($this->connection->fetchOne(
            "SELECT id FROM tl_page WHERE alias = ? AND type = 'regular' LIMIT 1",
            [self::PAGE_ALIAS],
        ) ?: 0);

        if ($pageId > 0) {
            // Heal an existing demo page: hide it from the menu and adopt a site layout.
            if ($layoutId > 0) {
                // subpageLayout = 0 → "inherit page layout" (the page has no subpages); also
                // clears a stale value that pointed at the now-removed demo layout.
                $this->connection->executeStatement(
                    "UPDATE tl_page SET hide = 1, includeLayout = '1', layout = ?, subpageLayout = 0, published = '1', robots = ? WHERE id = ?",
                    [$layoutId, self::ROBOTS, $pageId],
                );
            } else {
                $this->connection->executeStatement('UPDATE tl_page SET hide = 1, robots = ? WHERE id = ?', [self::ROBOTS, $pageId]);
            }
        } else {
            $rootId = (int) $this->connection->fetchOne(
                "SELECT id FROM tl_page WHERE type = 'root' AND published = 1 ORDER BY sorting, id LIMIT 1",
            );

            if ($rootId <= 0 || $layoutId <= 0) {
                // No reachable root or no site layout to adopt – skip (demo without a form page).
                return 0;
            }

            $sorting = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sorting), 0) + 128 FROM tl_page WHERE pid = ?', [$rootId]);

            // subpageLayout = 0 → "inherit page layout" (the form page has no subpages).
            $this->connection->executeStatement(
                'INSERT INTO tl_page (pid, sorting, tstamp, title, alias, type, published, hide, includeLayout, layout, subpageLayout, robots) '
                ."VALUES (?, ?, UNIX_TIMESTAMP(), ?, ?, 'regular', 1, 1, '1', ?, 0, ?)",
                [$rootId, $sorting, self::PAGE_TITLE, self::PAGE_ALIAS, $layoutId, self::ROBOTS],
            );
            $pageId = (int) $this->connection->lastInsertId();
        }

        $this->ensureArticleWithModule($pageId, $moduleId);
        $this->cleanupOldLayout();

        return $pageId;
    }

    /**
     * The site's own layout to adopt: the one assigned to the most pages, excluding the
     * former dedicated demo layout. 0 when the site has no layout in use.
     */
    private function findSiteLayout(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT p.layout FROM tl_page p WHERE p.includeLayout = '1' AND p.layout > 0 "
            .'AND p.layout NOT IN (SELECT id FROM tl_layout WHERE name = ?) '
            .'GROUP BY p.layout ORDER BY COUNT(*) DESC LIMIT 1',
            [self::OLD_LAYOUT_NAME],
        );
    }

    private function ensureArticleWithModule(int $pageId, int $moduleId): void
    {
        $articleId = (int) ($this->connection->fetchOne(
            'SELECT id FROM tl_article WHERE pid = ? AND alias = ? LIMIT 1',
            [$pageId, self::PAGE_ALIAS],
        ) ?: 0);

        if ($articleId <= 0) {
            $this->connection->executeStatement(
                "INSERT INTO tl_article (pid, sorting, tstamp, title, alias, inColumn, published) "
                ."VALUES (?, 128, UNIX_TIMESTAMP(), ?, ?, 'main', 1)",
                [$pageId, self::PAGE_TITLE, self::PAGE_ALIAS],
            );
            $articleId = (int) $this->connection->lastInsertId();
        }

        $hasModule = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_content WHERE pid = ? AND ptable = 'tl_article' AND type = 'module' AND module = ?",
            [$articleId, $moduleId],
        );

        if ($hasModule <= 0) {
            // tl_content uses "invisible" (0 = visible) – no "published" column.
            $this->connection->executeStatement(
                'INSERT INTO tl_content (pid, ptable, sorting, tstamp, type, module) '
                ."VALUES (?, 'tl_article', 128, UNIX_TIMESTAMP(), 'module', ?)",
                [$articleId, $moduleId],
            );
        }
    }

    /**
     * Removes the former dedicated demo layout – it is no longer used now that the page
     * adopts a site layout. Only the bundle's own marker-named layout is touched.
     */
    private function cleanupOldLayout(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_layout WHERE name = ?', [self::OLD_LAYOUT_NAME]);
    }

    private function ensureTheme(): int
    {
        $id = $this->connection->fetchOne('SELECT id FROM tl_theme WHERE name = ? LIMIT 1', [self::THEME_NAME]);

        if (false !== $id) {
            return (int) $id;
        }

        $this->connection->executeStatement(
            "INSERT INTO tl_theme (tstamp, name, author, templates) VALUES (UNIX_TIMESTAMP(), ?, 'Workflow', '')",
            [self::THEME_NAME],
        );

        return (int) $this->connection->lastInsertId();
    }

    private function ensureModule(int $themeId): int
    {
        $id = $this->connection->fetchOne(
            "SELECT id FROM tl_module WHERE pid = ? AND type = 'workflow_form' AND name = ? LIMIT 1",
            [$themeId, self::MODULE_NAME],
        );

        if (false !== $id) {
            return (int) $id;
        }

        $this->connection->executeStatement(
            "INSERT INTO tl_module (pid, tstamp, name, type) VALUES (?, UNIX_TIMESTAMP(), ?, 'workflow_form')",
            [$themeId, self::MODULE_NAME],
        );

        return (int) $this->connection->lastInsertId();
    }
}
