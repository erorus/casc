# CASC Extractor

This is a command-line utility written in PHP that extracts files from World of Warcraft CASC archives.

## Install

Clone this repo, and run `composer install` to get the requirements.

## Usage

```  
  php casc.php --files <path> --out <path> [--wow <path>] [--cache <path>] [--program <wow>] [--region <us>] [--locale <enUS>]
  php casc.php --help

-f, --files    Required. Path of a text file listing the files to extract, one per line. 
-o, --out      Required. Destination path for extracted files.

-w, --wow      Recommended. Path to World of Warcraft's base directory.

-c, --cache    Optional. Path for CASC's file cache. [default: $HOME/.casc-cache]
-p, --program  Optional. The NGDP program code (wow, wow_beta, wowt). [default: wow] 
-r, --region   Optional. Region. (us, eu, cn, tw, kr) [default: us]
-l, --locale   Optional. Locale. [default: enUS]

-h, --help     This help message.
```

Example:

`php casc.php --files dbcs.all.txt --out ./out --wow "$HOME/.wine/drive_c/Program Files (x86)/World of Warcraft"`

## OS Compatibility

This was written in a Linux environment, though it should also function in Windows. Windows compatibility has not yet been tested.

This requires at least 64-bit PHP 7.2.

## Game Compatibility

This was designed specifically to extract data for World of Warcraft. Parts of it may happen to be compatible with other Blizzard games, but they have not been tested.

## Development Status

This tool is considered complete. It should be able to extract most files from both local and remote CASC sources.

Features that are not planned to be supported:
* Patch archives (unnecessary since we aren't going to patch old files)
* The Encoding specification table (only required for patching)
* Background downloader files

## Disclaimer

This work is neither endorsed by nor affiliated with Blizzard Entertainment.

World of Warcraft, Warcraft, Battle.net and Blizzard Entertainment are trademarks or registered trademarks of Blizzard Entertainment, Inc. in the U.S. and/or other countries.

## Thanks

I could not have done this without the documentation at [the WoWDev wiki](https://wowdev.wiki/CASC). Thanks to those who contribute there!

Some BLTE processing details (especially for encrypted blocks) came from [the CASCExplorer project](https://github.com/WoW-Tools/CASCExplorer).

[![Become a Patron!](https://everynothing.net/patronButton.png)](https://www.patreon.com/bePatron?u=4445407)

## License

Copyright 2017 Gerard Dombroski

Licensed under the Apache License, Version 2.0 (the "License");
you may not use these files except in compliance with the License.
You may obtain a copy of the License at

  http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
