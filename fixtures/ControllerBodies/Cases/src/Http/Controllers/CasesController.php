<?php

namespace BodyCases\Http\Controllers;

use BodyCases\Actions\SavePost;
use BodyCases\Jobs\WriteTask;
use BodyCases\Models\Post;
use BodyCases\Queries\FindPosts;
use BodyCases\Reporting\Read;
use BodyCases\Search\ReadLookup;
use BodyCases\Workflow\Publish;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request as Input;
use Illuminate\Support\Facades\DB as Database;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator as Check;
use Inertia\Inertia;

class ValidationController
{
    public function facade(): void
    {
        Check::make([], []);
    }

    public function globalHelper(): void
    {
        validator([]);
    }

    public function request(Input $input): void
    {
        $input->validate([]);
    }

    public function controller(Input $input): void
    {
        $this->validate($input, []);
    }

    public function requestHelper(): void
    {
        request()->validate([]);
    }

    public function closure(Input $input): void
    {
        $run = fn () => $input->validate([]);
    }

    public function variable(): void
    {
        $request->validate([]);
    }
}

class NegotiationController
{
    public function wants(Input $input): void
    {
        $input->wantsJson();
    }

    public function expects(Input $input): void
    {
        $input->expectsJson();
    }

    public function ajax(Input $input): void
    {
        $input->ajax();
    }

    public function accepts(Input $input): void
    {
        $input->acceptsJson();
    }
}

class PersistenceController
{
    private Post $post;

    public function createModel(): void
    {
        Post::create([]);
    }

    public function updateModel(Post $post): void
    {
        $post->update([]);
    }

    public function saveModel(Post $post): void
    {
        $post->save();
    }

    public function deleteModel(Post $post): void
    {
        $post->delete();
    }

    public function forceDeleteModel(Post $post): void
    {
        $post->forceDelete();
    }

    public function database(): void
    {
        Database::table('posts')->insert([]);
    }

    public function builder(Builder $posts): void
    {
        $posts->where('id', 1)->delete();
    }

    public function fluent(): void
    {
        Post::query()->where('id', 1)->update([]);
    }

    public function local(): void
    {
        $post = new Post;
        $post->save();
    }

    public function property(): void
    {
        $this->post->delete();
    }

    public function nullable(?Post $post): void
    {
        $post?->save();
    }
}

class StaticController
{
    public function store(): void
    {
        SavePost::run();
        FindPosts::run();
    }
}

class TypedController
{
    public function store(SavePost $save, FindPosts $find): void
    {
        $save->handle();
        $find->handle();
    }
}

class PropertyController
{
    private SavePost $save;

    public function __construct(private FindPosts $find) {}

    public function store(): void
    {
        $this->save->handle();
        $this->find->handle();
    }
}

class InvokableController
{
    public function __invoke(SavePost $save, FindPosts $find): void
    {
        $save();
        $find();
    }
}

class LocalController
{
    public function store(): void
    {
        $save = new SavePost;
        $find = new FindPosts;
        $save->handle();
        $find->handle();
    }
}

class ContainerController
{
    public function store(): void
    {
        app(SavePost::class)->handle();
        resolve(FindPosts::class)->handle();
    }
}

class NamespacesController
{
    public function store(): void
    {
        Publish::run();
        Read::run();
    }
}

class SuffixesController
{
    public function store(): void
    {
        WriteTask::run();
        ReadLookup::run();
    }
}

class BoundaryController
{
    public function store(): void
    {
        \BodyCases\ActionsExtra\Publish::run();
        \BodyCases\QueriesExtra\Read::run();
    }
}

class HelpersController
{
    protected function persist(): void {}

    private function page(): mixed
    {
        return Inertia::render('Posts');
    }

    private function json(): mixed
    {
        return Response::json([]);
    }

    private function toast(): mixed
    {
        return back()->toastSuccess('Saved');
    }

    private function sideEffect(): mixed
    {
        $x = 1;

        return response()->json([]);
    }

    private function disguised(): mixed
    {
        return response()->json(service());
    }
}

class UnmappedController
{
    public function store(): void
    {
        validator([]);
    }
}
