<?php

use Illuminate\Support\Facades\Route;


use App\Models\Blog;

Route::get('/', function () {
    $blog = Blog::first(); // Get the first blog
    return view('welcome', compact('blog'));
});
