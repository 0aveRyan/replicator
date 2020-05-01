# 0aveRyan/replicator

Replicator empowers developers to create new WordPress code projects from Mustache templates using WP-CLI.

_(This is the abstract library powering **[0averyan/replicate-command](https://github.com/0aveRyan/replicate-command)** -- consider extending from there first)._

## Quick Start

#### Want to try Replicator? 

1. `wp package install git@github.com:0averyan/replicate-command.git`
2. `wp replicate plugin|theme|package`
3. 🎉 **new project replicated!**

#### Want to extend Replicator?

`composer install pressnitro/replicator`

&

Follow the Basic Setup (Common Class vs. Base Class?)

## What's it for?

Replicator is designed to:
* ⚡️ Make **starting** new WordPress projects _faster_.
* 🗂 Make **unifying** code style and file organization across projects _easier_.
* 📉 **Reduce & reuse** work involved in building _custom_ boilerplates.

## Included Features

* 🔍 **Scan for existing folders and files**, with built-in handling of backups, override and deletion.
* ➕ **Easy side-loading** of dynamic files (i.e. `composer.json` or `package.json`) or remotely-sourced files (i.e. hit the GitHub API for the latest copy of a file).
* 📋 **Handles basic prompts** like labels, slugs, namespacing, author details and common metadata out-of-the-box.
* 🗃 **Handles project licensing, README files and test setup** out-of-the-box.
* 🛠 **Built for flexibility & extensibility** -- take on as many or as few opinions as you'd like!
* 📚 **Tons of examples** -- check out the Wiki.

## What's happening under-the-hood?

* Replicator has **zero dependencies on WordPress Core** -- it can be used to build Installer/Setup commands -- and then use them!
* Replicator **creates interactive prompts** using thephpleague/climate and internal WP-CLI utilities.
* Replicator **interacts with the filesystem** using thephpleague/flysystem.
* Replicator **renders Mustache templates** -- both included and custom -- using mustache/mustache.
* Replicator will **write built files (or a .zip)** so you can _**start building WordPress products fast**_!
* (Optional) Replicator **uses Environment Variables or PHP Constants to preset common values** like Author Name, Author Email, Author URL, GitHub Username, etc so you don't need to be prompted every time.

## What's the Difference: `Common` vs. `Base` classes?

`\PressNitro\Replicator\Common` and `PressNitro\Replicator\Base` are both abstract classes you can extend to build your own replication classes _(you can also extend the Plugin, Theme or Package classes in `pressnitro/replicate-command`)_.

**The `Common` class extends the `Base` class.**

The Base Class...
* Handles reading of a Templates directory and writing to the Destination directory.
* Handles automatically setting the Destination directory based on  type of project (can be overriden).
* Handles destination directory checks, backups and/or overrides.
* (Optional) Handles .zip generation.

The Common Class...
* Handles common prompts for labels, slugs, namespaces, author and Project URLs.
* Handles injecting a LICENSE file.
* Handle injecting an organized README.md file.

## Disclaimers

#### It's _strongly discouraged_ to use Replicator on a live production or staging server -- it's intended for local development only.

While it's _possible_ to run Replicator anywhere WP-CLI can run, it's strongly discouraged to run on a live, internet-connected server.

**Replicator is not performance-tuned or security-tested for a live server.** Please use it with a tool like Lando, Docksal, MAMP, DesktopServer or Local by Flywheel either on your local machine or inside a local, virtual machine.

#### Your mileage with WP-CLI on Windows may vary.

If you're developing on Windows, please consider running Replicator inside a virtual Unix-based machine like VVV, Docker container, etc. Replicator aims to run smoothly in all environments, but is only checked in Unix-based environments.

Also, the Radio Button and Checkbox inputs in CLImate don't work on Windows.

## License

Copyright (C) 2019 David Ryan

Replicator is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

Replicator is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with Replicator. If not, see <https://www.gnu.org/licenses/>.