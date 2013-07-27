SVN rebase
==========
A missing (?) functionality for svn.
Suppose you've created a feaure branch `cool-branch` from trunk at revision X, and a few weeks later, at revision Y, you realize that you really, really need
a bugfix which was done in trunk few days ago.
If you are like me you would probably do something like:


     svn remove cool-branch
     svn cp trunk cool-branch
     svn switch cool-branch
     svn merge -r X:Y cool-branch@Y
     svn ci -m "remerged"

Things get messier, if you want the new branch, and also keep the old branch, or when you want to rebase to completelly different branch, not trunk,
or if you want to recreate commits one-by-one, to keep the history, authors, and commit messages.

With this simple tool you get all of these.
Simply run:

     ./svn_rebase
     ./svn_rebase --continue

And if something goes wrong, the control is handed back to you, so you can resolve conflicts, and `./svn_rebase --continue`.

Options
=======
You may find following options usefull:

 * --new-url PATH   Use PATH instead of original branch name as the new branch name.
 * --source-url PATH   Use PATH instead of source of `svn copy source branch` which created this branch when creating new branch. 
 * --plan FILENAME   Use FILENAME instead of .svn_rebase.plan to store the plan.
 * --single-commit    Instead of replicating original changesets one-by-one, commit everything at once. Actually there will be at least 3 commits : remove, copy, merge.
 * --message MESSAGE   When used together with --single-commit, allows you to specify MESSAGE to be used for the single commit.


