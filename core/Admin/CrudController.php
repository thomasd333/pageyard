<?php

namespace Pageyard\Admin;

use Pageyard\Request;
use Pageyard\Session;

defined('PAGEYARD') or exit;

/**
 * List + edit form controller with the usual Joomla toolbar tasks.
 * Subclasses set LIST_VIEW / ITEM_VIEW, implement form() and store().
 */
abstract class CrudController extends Controller
{
    /** e.g. "pages" (list) and "page" (edit form). */
    protected const LIST_VIEW = '';
    protected const ITEM_VIEW = '';

    /** Shows the edit form for $id (0 = new). */
    abstract protected function form(int $id): void;

    /** Saves the posted form and returns the id; on validation errors re-renders the form and exits. */
    abstract protected function store(): int;

    /** GET view=<item>&id=N, or the "Edit" toolbar button with a checked row. */
    public function edit(): void
    {
        if (Request::isPost()) {
            $this->redirect('view=' . static::ITEM_VIEW . '&id=' . (Request::ids()[0] ?? 0));
        }
        $this->form(Request::int('id'));
    }

    public function add(): void
    {
        $this->redirect('view=' . static::ITEM_VIEW);
    }

    public function apply(): void
    {
        $id = $this->store();
        $this->redirect('view=' . static::ITEM_VIEW . "&id=$id");
    }

    public function save(): void
    {
        $this->store();
        $this->redirect('view=' . static::LIST_VIEW);
    }

    public function save2new(): void
    {
        $this->store();
        $this->redirect('view=' . static::ITEM_VIEW);
    }

    public function cancel(): void
    {
        $this->redirect('view=' . static::LIST_VIEW);
    }

    /** Standard edit-form toolbar; $copy adds "Save as Copy" (the controller implements save2copy). */
    protected function formToolbar(string $prefix, bool $isNew, bool $copy = false): void
    {
        $this->button("$prefix.apply", t('TOOLBAR_SAVE'), 'bi-check-lg', 'btn-success');
        $this->button("$prefix.save", t('TOOLBAR_SAVE_CLOSE'), 'bi-check2-all', 'btn-outline-success');
        $this->button("$prefix.save2new", t('TOOLBAR_SAVE_NEW'), 'bi-plus-lg', 'btn-outline-success');
        if ($copy && !$isNew) {
            $this->button("$prefix.save2copy", t('TOOLBAR_SAVE_COPY'), 'bi-copy', 'btn-outline-success');
        }
        $this->button("$prefix.cancel", t($isNew ? 'TOOLBAR_CANCEL' : 'TOOLBAR_CLOSE'), 'bi-x-lg', 'btn-outline-danger', novalidate: true);
    }

    /** Redirects to the list with a "not found" message. */
    protected function notFound(): never
    {
        Session::flash(t('ERR_NOT_FOUND'), 'warning');
        $this->redirect('view=' . static::LIST_VIEW);
    }
}
