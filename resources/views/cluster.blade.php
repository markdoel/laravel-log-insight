@extends('log-insight::layout')

@section('title', 'Cluster #'.$clusterId.' · Log Insight')

@section('content')
    @livewire('log-insight.cluster-page', ['clusterId' => $clusterId])
@endsection
