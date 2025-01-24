# wp-cli-move

Sync your WordPress content (database and uploads) between stages using the power of WP-CLI aliases.

## Install

Using composer:

```sh
composer require n5s/wp-cli-move --dev
```

Using `wp package install`:

```sh
wp package install nlemoine/wp-cli-move:^0.1.0
```

## Requirements

The requirements must be met on both stages.

- SSH access
- WP-CLI
- mysql/mysqldump
- rsync
- gzip (optional, can be disabled with the `--disable-compress` flag)

Before running commands, make sure you have WP-CLI aliases set up. This can be done either with the [`wp cli alias`](https://developer.wordpress.org/cli/commands/cli/alias/) command or by editing your `wp-cli.yml` file.

Once you're done, quickly check that remote WP-CLI commands work as expected:

```sh
wp @your-alias option get home

# It should print your alias home URL
https://example.org
```

For more information about alias configuration, refer to the following WP-CLI documentation:

- https://make.wordpress.org/cli/handbook/guides/running-commands-remotely/#aliases
- https://make.wordpress.org/cli/handbook/references/config/

## Usage

Depending on the sync direction, use either the `pull` or `push` commands.

```sh
wp move pull/push [<alias>] [--db] [--uploads] [--disable-compress] [--dry-run]
```

If you omit the `--db` or `--uploads` flags, both data types will be synced by default.

Note that the `<alias>` argument is optional. Configured aliases will be shown in a menu to choose from if left empty.

> [!CAUTION]
> Just like any tool that manipulates your data, it's **always a good idea to make a backup before running commands**.
>
> Especially when syncing uploads, which uses the `rsync` command with the `--delete` flag under the hood and can wipe all your media files if used incorrectly.
>
> **Be sure to know what you're doing.**

### Options

Both `pull` and `push` commands use the same options.

- `[<alias>]`: The alias you want to sync with.
- `--db`: Sync only the database.
- `--uploads`: Sync only the uploads.
- `--disable-compress`: Disable database dump compression.
- `--dry-run`: Print the command sequence without making any changes.

> [!NOTE]
> Each time you sync your database from one stage to another, `wp-cli-move` will locally backup the database of the synced stage (a local database dump when pulling, a remote database dump when pushing).

### Examples

### Pulling content

Pull your production content to your local environment:

```sh
wp move pull @production
```

> [!TIP]
> Using `@` as declared in `wp-cli.yml` is optional. For example, `production` and `@production` will resolve the same alias.

### Pushing content

Push your local content to your staging environment:

```sh
wp move push staging
```

## Credits

This WP-CLI package aims to replace the (still working but unmaintained) awesome [Wordmove](https://github.com/welaika/wordmove) Ruby gem 💎. It has been a time and life saver for many years. I'll be forever grateful to [@alessandro-fazzi](https://github.com/alessandro-fazzi) for creating it! 🙌

Although [Wordmove](https://github.com/welaika/wordmove) is a great and handy tool for your daily WordPress work, some reasons led me to come with a simpler, more WordPress flavoured alternative:

- It has become harder to easily install the gem over the years as the required Ruby version has been deprecated, especially for non-Ruby developers (at least for me).
- Most importantly, [an idea I submitted years ago](https://github.com/welaika/wordmove/issues/601#issue-612726521) has never been implemented. This feature would remove a lot of tedious configuration setup (database credentials, etc.).
- It's written in Ruby 😄
