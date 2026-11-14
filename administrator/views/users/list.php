<?php
/**
 * Users: Manage.
 * @var array $state
 * @var list<array> $items
 * @var Pageyard\Pagination $pagination
 * @var int $limit
 */

use Pageyard\Admin\Form;
use Pageyard\Admin\Grid;
use Pageyard\Auth;
use Pageyard\Uri;

defined('PAGEYARD') or exit;

$filtered = $state['search'] !== '' || $state['status'] !== '' || $state['kind'] !== '';
$pending = ['email' => t('USERS_PENDING_EMAIL'), 'admin' => t('USERS_PENDING_ADMIN')];
?>
<form class="py-filters" method="get" action="<?= e(Uri::admin()) ?>">
    <input type="hidden" name="view" value="users">
    <?= Grid::search($state['search'], t('USERS_SEARCH_HINT')) ?>
    <?= Grid::filter('status', t('USERS_STATUS'), ['1' => t('USERS_ACTIVE'), '0' => t('USERS_BLOCKED_STATE'), 'pending' => t('USERS_PENDING')], $state['status']) ?>
    <?= Grid::filter('kind', t('USERS_KIND'), ['admin' => t('USERS_KIND_ADMIN'), 'registered' => t('USERS_KIND_REGISTERED')], $state['kind']) ?>
    <?= $filtered ? Grid::clear('users') : '' ?>
    <?= Grid::limit($limit) ?>
</form>

<form id="adminForm" method="post" action="<?= e(Uri::admin()) ?>">
    <?= Form::token() ?>
    <?php if (!$items): ?>
        <?= Grid::empty($filtered) ?>
    <?php else: ?>
        <div class="card py-card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 py-list">
                    <thead>
                    <tr>
                        <th class="py-col-check"><?= Grid::checkAll() ?></th>
                        <th scope="col"><?= Grid::sort('users', $state, 'name', t('USERS_NAME')) ?></th>
                        <th scope="col" class="d-none d-md-table-cell"><?= Grid::sort('users', $state, 'username', t('USERS_USERNAME')) ?></th>
                        <th scope="col" class="text-center"><?= Grid::sort('users', $state, 'active', t('USERS_STATUS')) ?></th>
                        <th scope="col" class="d-none d-sm-table-cell"><?= Grid::sort('users', $state, 'admin', t('USERS_KIND')) ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?= Grid::sort('users', $state, 'email', t('USERS_EMAIL')) ?></th>
                        <th scope="col" class="d-none d-lg-table-cell"><?= Grid::sort('users', $state, 'last_login', t('USERS_LAST_LOGIN')) ?></th>
                        <th scope="col" class="text-end d-none d-md-table-cell"><?= Grid::sort('users', $state, 'id', 'ID') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $u): ?>
                        <tr>
                            <td class="py-col-check"><?= Grid::check($i, (int) $u['id'], $u['name']) ?></td>
                            <th scope="row" class="fw-normal">
                                <a href="<?= e(Uri::admin('view=user&id=' . $u['id'])) ?>"><?= e($u['name']) ?></a>
                                <?php if ((int) $u['id'] === Auth::id()): ?><span class="badge text-bg-secondary ms-1"><?= e(t('USERS_YOU')) ?></span><?php endif ?>
                                <?php if (isset($pending[$u['pending']])): ?><span class="badge text-bg-warning ms-1"><?= e($pending[$u['pending']]) ?></span><?php endif ?>
                            </th>
                            <td class="d-none d-md-table-cell"><?= e($u['username']) ?></td>
                            <td class="text-center"><?= Grid::state($i, (bool) $u['active'], 'users', t('USERS_ACTIVE'), t('USERS_BLOCKED_STATE'), 'block', 'activate') ?></td>
                            <td class="d-none d-sm-table-cell small"><?= $u['admin'] ? '<i class="bi bi-shield-lock me-1" aria-hidden="true"></i>' . e(t('USERS_KIND_ADMIN')) : e(t('USERS_KIND_REGISTERED')) ?></td>
                            <td class="d-none d-lg-table-cell"><a href="mailto:<?= e($u['email']) ?>" class="link-secondary"><?= e($u['email']) ?></a></td>
                            <td class="d-none d-lg-table-cell"><?= $u['last_login'] ? e(py_date($u['last_login'])) : '<span class="text-body-secondary">' . e(t('USERS_NEVER')) . '</span>' ?></td>
                            <td class="text-end d-none d-md-table-cell text-body-secondary"><?= (int) $u['id'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
    <?= Grid::footer($pagination) ?>
</form>
