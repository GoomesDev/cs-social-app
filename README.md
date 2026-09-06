# CS Social App — Backend

A backend for a Counter-Strike companion app, designed to help players follow their performance and connect with friends through shared stats and competition.

## About the project

CS Social App brings Steam profiles and gameplay statistics together as the foundation for a social experience around Counter-Strike. The goal is to make it easier to understand your progress, compare results with friends, and celebrate achievements.

This repository contains the backend API that supports that experience.

## What is already in place

- **Steam integration:** an initial sign-in flow, player profile synchronization, and API token creation.
- **Player profiles:** stored Steam identity, display name, avatar, and profile link.
- **Stat snapshots:** collection and storage of cumulative gameplay statistics, including kills, deaths, wins, rounds, MVPs, and headshots.
- **Performance metrics:** K/D ratio, win rate, headshot percentage, and custom rating and impact scores.
- **Social data foundation:** database structures for friendships, weekly rankings, and badges.

The rating and impact scores are custom project metrics.

## Project status

**In development.** The core profile and statistics code is in place, while authentication validation, daily and weekly comparisons, and background score recalculation still need improvements. Friends, rankings, and badges currently have database foundations; their API features are not yet implemented.

## What comes next

- Complete and validate the Steam sign-in flow.
- Improve daily and weekly performance tracking.
- Add friend connections and weekly leaderboards.
- Introduce achievement badges.
- Expand automated tests and improve reliability.

## Built with

PHP · Laravel · Laravel Sanctum · Steam Web API · PHPUnit
