**Version**: 5.0

What is Scalr?
==============

Scalr is an open-source Cloud Management Platform (CMP). It leverages the
APIs exposed by compatible Cloud Platforms (including AWS, GCE, OpenStack,
CloudStack, and more) to provide users with a high-level and productive
interface to their organization's cloud resources.


Mission Statement
=================

Multi-Cloud Support
-------------------

Scalr provides a single UI, API, and CLI to access resources across any Cloud
Platform, public or private.

Scalr strives to expose a common set of features across all clouds, but does
not limit its users to a dumbed-down feature set (e.g. IAM Instance Profiles
are available through Scalr for AWS, even if they have no equivalent in other
clouds).


High-Level Declarative Primitives rather than Low-Level Imperative Instructions
-------------------------------------------------------------------------------

Unlike a Cloud Platform's UI or API, Scalr intentionally does not expose the
ability to imperatively provision resources  (e.g. there is no API call to
"provision an instance" in Scalr).

Instead, Scalr enables its end-users to declare the infrastructure they'd like
to deploy through high-level primitives such as Farms, Roles, and Farm Roles
(*"I'd like this application tier to be deployed in this cloud across 8 to 12
hosts, each with one data volume"*). In turn, Scalr maintains that state by
making the appropriate imperative API calls to the underlying Cloud Platforms
that the user selected (e.g. AWS, OpenStack, ...).

When unexpected conditions arise (e.g. a server crashes or is accidentally
terminated), Scalr automatically reconciles the resulting infrastructure with
the specification that was declared by the user (e.g. by provisioning and
configuring a replacement instance).

We believe (and so do Scalr users) that this encourages end-users to adopt
cloud architecture best practices that work at scale ("cloud-native") — i.e.
not to reason about individual resources, but about the overall desired state
of their infrastructure (a design practice epitomized by the "cattle, not pets"
motto).


Usable by Both Developers and IT
--------------------------------

Scalr strives to reconcile the needs of both Developers and IT.

At a high-level, developers need self-service, and IT needs control. While the
two might seem incompatible they usually aren't: developers don't mind
complying with IT policies; provided that doesn't slow them down.

To that end, Scalr seeks to provide IT with the ability to prepare and
automatically enforce policies that ensure that every piece of infrastructure
that developers provision is made compliant with the organization's policies,
without requiring additional effort on the part of developers.

Using Scalr, this is achieved through the enforcement of specific cloud
configurations (e.g. VPC, with Governance), the definition of user-level
restrictions (Role-Based Access Control), the execution of host-level
compliance scripts (Global Orchestration), and integration with external
systems (Webhooks and API).


More Information
----------------

Learn more about Scalr on the [Scalr Website][10].


Installation Instructions
=========================

[Installation instructions for Scalr][20] can be found on the Scalr Wiki.

[Instructions to upgrade from an earlier Scalr version][21] can be found there
too.


-- The Scalr Team

----

*In memory of Alexey Kovalyov.
Brilliant engineer, caring brother, and most excellent friend.
This project is dedicated to you.*

[10]: http://www.scalr.com/ "Scalr Product Overview"
[20]: https://scalr-wiki.atlassian.net/wiki/x/XgQb "Installation Instructions"
[21]: https://scalr-wiki.atlassian.net/wiki/x/FoAs "Upgrade Instructions"

