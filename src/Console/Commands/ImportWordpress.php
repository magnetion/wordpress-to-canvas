<?php

namespace Magnetion\WordpressToCanvas\Console\Commands;

use DB;
use Illuminate\Console\Command;
use Magnetion\Colossus\DynamicDB;

class ImportWordPress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'Magnetion:ImportWordPress';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imports WordPress posts, categories and tags to Canvas';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Started');

        $dbHost = $this->ask('Database Hostname?');
        $dbUsername = $this->ask('Database Username?');
        $dbPassword = $this->ask('Database Password?');
        $dbDatabase = $this->ask('Database?');

        $dbInfo = [
            'driver' => 'mysql',
            'host' => $dbHost,
            'port' => '3306',
            'database' => $dbDatabase,
            'username' => $dbUsername,
            'password' => $dbPassword
        ];

        $otf = new DynamicDB($dbInfo);

        // authors *****************************************************************************************************
        $wp_users = $otf->getTable('wp_users');

        $users = $wp_users->get();

        foreach($users as $user):
            $authorData = [
                'first_name' => '',
                'last_name' => '',
                'email' => $user->user_email,
                'display_name' => $user->display_name,
                'website' => $user->user_url,
                'status' => 1,
                'external_id' => $user->ID
            ];

            DB::table('blog_author')->insert($authorData);
        endforeach;

        $this->info('Authors Imported');
        // END: authors ************************************************************************************************


        // categories and tags *****************************************************************************************
        $wp_term_taxonomy = $otf->getTable('wp_term_taxonomy');

        $terms = $wp_term_taxonomy
            ->join('wp_terms', 'wp_term_taxonomy.term_id', '=', 'wp_terms.term_id')
            ->select('wp_terms.*', 'wp_term_taxonomy.taxonomy', 'wp_term_taxonomy.description', 'wp_term_taxonomy.parent', 'wp_term_taxonomy.term_id as external_id')
            ->whereIn('wp_term_taxonomy.taxonomy', ['category', 'post_tag'])
            ->get();

        foreach($terms as $term):
            if($term->taxonomy == 'category') :
                $metaDataType = 'category';
            else :
                $metaDataType = 'tag';
            endif;

            // get parent if needed

            $metaData = [
                'name' => $term->name,
                'slug' => $term->slug,
                'type' => $metaDataType,
                'parent' => 0,
                'external_id' => $term->external_id
            ];

            DB::table('blog_metadata')->insert($metaData);
        endforeach;

        $this->info('Categories and Tags Imported');
        // END: categories and tags ************************************************************************************


        // posts *******************************************************************************************************
        $wp_posts = $otf->getTable('wp_posts');

        $posts = $wp_posts->whereIn('post_type', ['post', 'page'])->get();

        foreach($posts as $post):
            // get author
            $wp_user = $otf->getTable('wp_users');
            $user = \Magnetion\Colossus\Models\Author::where('external_id', $post->post_author)->first();

            // get status
            if($post->post_status == 'publish') :
                $postStatus = 1;
            else :
                $postStatus = 0;
            endif;

            // comment status
            if($post->comment_status == 'open') :
                $commentStatus = 1;
            else :
                $commentStatus = 0;
            endif;

            $postData = [
                'author_id' => $user->id,
                'publish_date' => strtotime($post->post_date),
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'summary' => $post->post_excerpt,
                'status' => $postStatus,
                'comments_enabled' => $commentStatus,
                'comment_count' => 0,
                'post_type' => $post->post_type,
                'external_id' => $post->ID
            ];

            $post_id = DB::table('blog_post')->insertGetId($postData);

            // taxonomy
            $wp_term_relationships = $otf->getTable('wp_term_relationships');
            $relationships = $wp_term_relationships
                ->join('wp_term_taxonomy', 'wp_term_relationships.term_taxonomy_id', '=', 'wp_term_taxonomy.term_taxonomy_id')
                ->select('wp_term_taxonomy.term_id')
                ->where('wp_term_relationships.object_id', $post->ID)
                ->whereIn('wp_term_taxonomy.taxonomy', ['category', 'post_tag'])
                ->get();

            foreach($relationships as $rs) :
                $metaDataID = \Magnetion\Colossus\Models\MetaData::where('external_id', $rs->term_id)->first();

                $taxonomyData = [
                    'post_id' => $post_id,
                    'metadata_id' => $metaDataID->id
                ];

                DB::table('blog_taxonomy')->insert($taxonomyData);
            endforeach;

            // comments
            $wp_comments = $otf->getTable('wp_comments');
            $comments = $wp_comments->where('comment_post_ID', $post->ID)->where('comment_approved', 1)->where('comment_type', '<>', 'pingback')->orderBy('comment_ID')->get();

            foreach($comments as $comment) :
                if($comment->comment_parent <> 0) :
                    $getParent = \Magnetion\Colossus\Models\Comment::where('external_id', $comment->comment_parent)->first();
                    $commentParent = $getParent->id;
                else :
                    $commentParent = 0;
                endif;

                $commentData = [
                    'post_id' => $post_id,
                    'display_name' => $comment->comment_author,
                    'email' => $comment->comment_author_email,
                    'ip_address' => $comment->comment_author_IP,
                    'content' => $comment->comment_content,
                    'approved' => 1,
                    'parent_id' => $commentParent,
                    'comment_date' => strtotime($comment->comment_date),
                    'external_id' => $comment->comment_ID
                ];

                DB::table('blog_comment')->insert($commentData);
            endforeach;
        endforeach;

        $this->info('Posts Imported');
        // END: posts **************************************************************************************************


    }
}