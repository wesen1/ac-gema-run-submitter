# ac-gema-run-submitter
Tool that automates the steps required to submit a AssaultCube gema speedrun to speedrun.com


## About

The overall goal is to auto sync new ingame personal best times to the [AssaultCube speedrun.com leaderboard](https://www.speedrun.com/AssaultCube).
The speedrun.com leaderboard should be used for:
* Archiving the best scores per gema map per player (gema servers shutting down and taking all !maptops with them happened a lot during the history of gema)
* Proving with demo files that the runs are legit (at the moment it depends on the server settings if demos of each run are available and they are still not available to the public in most cases)
* Showing with videos of the gema runs how really well done runs look like, possibly convincing some people to try the game out themselves
* Showing that the game and the gema mode exist by making runs of the current day appear on the main page of speedrun.com


## Roadmap

### First version: Tool that submits a single run

The first goal should be to create a tool or script to submit a single gema run.

It should work with these inputs:
- Demo file
- Player IP and/or player name

And execute these steps:


#### 1. Normalize the demo file

See https://github.com/wesen1/ac-demo-processor

The input demos should be normalized for several reasons:

- The privacy of the players should be ensured (remove chat messages, possibly remove IPs)
- The runs should look the same no matter on which server the demo was recorded (remove server messages that show score times or MOTDs)
- A unique name per demo file should be generated to be able to properly archive the file (for example a player could submit a demo named `"mybestrun.dmo"` but servers submit demos like `"20210816_220909_127.0.0.1_28763_CTF_gema_la_momie_8mr.dmo"`)

Tasks:
- [X] Remove chat, server messages, voicecom sounds
- [X] Generate a file name from the demo information (maybe `<date>_<time>_<server ip>_<server port>_<map name>_<game mode>.dmo`)


#### 2. Generate a video of the run

See https://github.com/wesen1/ac-demo-videofier

- [X] Find best score time start and end timestamp + player cn
- [ ] Download the map and required packages, exit with an error if the map could not be found
- [X] Play back and record the demo, fast forward to (start - 3s) and record until (end + 3s); spectate player cn

##### 2.1 Maps and packages

Maps and packages should be auto downloaded by the videofier tool.
For this all relevant maps and all packages that they require must be collected.

A package server should be created that provides the maps and packages for the tool so that multiple versions of the same map are supported (the package server can provide maps as `<map name>.cgz.zip` (1x per map) + `<map name>_revision_<revision>.cgz.zip` (for each revision)).

- [ ] Collect all known and working gema maps (maybe in a git repository, one directory per map name and sub directories per map revision)
- [ ] Collect all mapmodels, textures, audio files that are required by the known gema maps
- [ ] Filter out duplicated packages and maps
- [ ] Verify that all packages may be distributed without violating copyrights
- [ ] A repository like https://github.com/assaultcube/akimbo2 could be used to collect the package and maps. An alternative would be to use a new archive.org item.
- [ ] Minimal proof of concept (1x texture, 1x mapsound, 1x mapmodel, 1x map)
- [ ] Create a docker image for a package server with all needed maps and packages
- [ ] Use the docker package server in the videofier's config


#### 3. Upload the demo to archive.org
- [X] Plan a directory structure (one item per map, sub directories for every 1000 demos?)
- [X] Check if the demo was already uploaded
- [X] Rename the new demo file if a different demo with the same name was already uploaded
- [X] Upload and get the download link


#### 4. Upload the generated video to YouTube
- [ ] Generate video title, description, etc.
- [ ] Upload and get the video link


#### 5. Submit the run to speedrun.com
- [ ] Add link to YouTube video and demo file to run info
- [ ] Auto verify the run
- [ ] Handle API limit (retry later when no new requests are allowed at the moment)
- [ ] Add this data to the submissions:
  - [ ] Category: Any%
  - [ ] Player: ingame name (guest, not linked to a speedrun.com user)
  - [ ] Level: gema map name
  - [ ] Time: Calculated time
  - [ ] Variables:
    - [ ] Weapon: Score weapon
    - [ ] Version: 1.2.0.2
  - [ ] Platform: PC
  - [ ] Date: Demo date
  - [ ] Video link: `<Link to YouTube video>`
  - [ ] Description: `<Link to demo on archive.org>`


### 6. Fill the leaderboards with scores for high quality gema maps

The leaderboards for some of the best maps should be set up first to give the best possible impression of gema to new players.
These maps could include:

* Ac_SuperGemaBySuCkeRs
* GEMA-12-by-Phoenix94
* GEMA-13-by-Phoenix
* Gema-By-Morrigan
* Gema-by-Patroclus
* gema-cityonfire
* gemaClock
* Gema_Dungeon
* Gema_H4X
* Gema-H4X-Castle
* gema_horror
* Gema_Kazumi
* gema_la_momie
* Gema-Magic
* gema.manfestoeh.forexperts
* gema.manfestoeh.streetchase
* Gema-Mines
* Gema_Of_Thrones
* gema_shorty
* GemaWinter
* Gibbed-Gema10
* gibbed-gema11
* Gibbed-Gema12
* gibbed-gema3
* gibbed-gema7
* gladi-gema1
* MountainGema
* RooftopGema
* SE-GEMA-PARKING
* Shuffles_Gema-2
* UrbanGema
* XX_Olympic_TimeRun_GEMA

Other good maps might be:

* CheapGema
* Foxy-grim_gema
* Gema2_Arnold
* GEMA-4.0_Phoenix.94
* GEMA-7-by-Phoenix94
* Gema-Alcatraz-by-Patroclus
* gema_BOW
* gema_BOW-4
* Gema-BR
* GEMA_I_HATE_PALM_TREES
* gema_SAW
* Gema_Tuty
* gema_warm_up
* PG_Pickup_gema


### Future Features

#### Server that provides an API for authorized servers to submit new runs

- [ ] Set up a HTTP server to which you can send "run submit" requests, these requests would be processed by the tool, then gema servers can send requests to that server on map change
  - [ ] Sibling docker containers are required for this (HTTP server runs inside docker container and needs to trigger actions in docker containers on the docker host)
- [ ] Allow the tool to load a fixed map ignoring the one that the demo requires (for duplicated maps)
- [ ] Sync the already existing maptops to speedrun.com


- POST /submitrun: Should also include which players improved their best score in that demo
- AssaultCube servers should send a request to the HTTP server on map end if 1+ players improved their best time(s)


##### Docker Setup

- php server(?)
- message-broker
- runsubmitter -> Use the one-time containers below:
  - demo-parser -> 1. Normalize demo, 2. Find best score times per player
  - ac-demo-videofier -> videofy each run
  - youtube-upload -> Upload video to youtube
  - internetarchive -> Upload demo to archive.org
  - speedrun.com client -> Submit run


#### Improve quality of YouTube videos

The quality of the videos on YouTube is not perfect, sometimes it is very blurry.
