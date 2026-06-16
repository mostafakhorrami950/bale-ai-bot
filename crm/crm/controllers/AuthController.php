<?php
namespace Controllers;

use Core\Auth;
use Core\Session;
use Core\View;

class AuthController
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            View::redirect('/dashboard');
        }
        View::render('auth/login', ['title' => 'ورود به سیستم']);
    }

    public function login(): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            Session::setFlash('danger', 'لطفا نام کاربری و رمز عبور را وارد کنید.');
            View::redirect('/login');
        }

        if (Auth::attempt($username, $password)) {
            Session::setFlash('success', 'خوش آمدید!');
            View::redirect('/dashboard');
        } else {
            Session::setFlash('danger', 'نام کاربری یا رمز عبور اشتباه است.');
            View::redirect('/login');
        }
    }

    public function logout(): void
    {
        Auth::logout();
        Session::setFlash('success', 'با موفقیت خارج شدید.');
        View::redirect('/login');
    }
}