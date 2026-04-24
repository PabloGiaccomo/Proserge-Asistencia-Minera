@extends('layouts.app')

@section('title', 'Nueva Mina')

@section('content')
@include('catalogos.minas._form', ['item' => $item])
@endsection
