<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Application\User\Command\DeleteUser;
use AudiEdiPipe\Application\User\Command\UpdateUser;
use AudiEdiPipe\Application\User\Query\GetUser;
use AudiEdiPipe\Application\User\Query\ListUsers;
use AudiEdiPipe\Domain\User\Role;
use AudiEdiPipe\Http\Response;

final class UserController extends Controller
{
    public function index(): Response
    {
        return $this->render('users/index', [
            'title' => $this->view->t('nav.users'),
            'users' => $this->query(new ListUsers()),
        ]);
    }

    public function form(): Response
    {
        $id = $this->request->intAttribute('id');
        $user = $id > 0 ? $this->query(new GetUser($id)) : null;
        if ($id > 0 && !is_array($user)) {
            return Response::notFound();
        }

        return $this->render('users/form', [
            'title' => $user === null ? $this->view->t('users.new') : (string) $user['username'],
            'editUser' => $user ?? ['id' => null, 'username' => '', 'email' => '', 'display_name' => '', 'role' => 'cutter', 'active' => 1],
            'roles' => Role::values(),
        ]);
    }

    public function save(): Response
    {
        $id = $this->nullableInt('id');
        if ($id === null) {
            $id = (int) $this->command(new CreateUser(
                $this->request->string('username'),
                $this->request->string('email'),
                (string) $this->request->input('password', ''),
                $this->request->string('role'),
                $this->request->string('display_name') ?: null,
            ));
        } else {
            $this->command(new UpdateUser(
                $id,
                $this->request->string('username'),
                $this->request->string('email'),
                $this->request->string('role'),
                $this->request->bool('active'),
                $this->request->string('display_name') ?: null,
                (string) $this->request->input('password', '') ?: null,
            ));
        }
        $this->flash('success', 'common.saved');

        return $this->redirect('/users');
    }

    public function delete(): Response
    {
        $this->command(new DeleteUser($this->request->intAttribute('id'), $this->userId()));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/users');
    }
}
