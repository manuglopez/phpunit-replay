<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Posts</title>
</head>
<body>
    <h1>Posts</h1>
    <ul>
        @foreach ($posts as $post)
            @include('posts._item', ['post' => $post])
        @endforeach
    </ul>
</body>
</html>
